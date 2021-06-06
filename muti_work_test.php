<?php
/**
 * Desc:
 * User: maozhongyu
 * Date: 2021/6/6
 * Time: 下午12:44
 */

/**
 * 多进程任务处理器
 * Class WorkerProcess
 */
class MultiProcessWorker
{
    public $workerNum = 1; //子进程个数,默认一个
    public $onWork = NULL; //工作空间的回调函数，用于自定义处理任务
    public $totalTaskNum = 1; //总任务数， 在跑脚本的时候，要考虑新增数据情况
    public $minTaskNum = 1; //最小任务数
    public $perWorkPageTaskNum;// 每个进程要处理的任务个数
    const  modePcntl = 1;  // pcntl模式
    const  modeSignleSwooleProcess = 2;// swoole Process模式,  https://wiki.swoole.com/#/process/process?id=process

    /**
     * WorkerProcess constructor.
     * @param int $workerNum 工作进程个数
     * @param int $totalTaskNum
     *              总任务数,可能是来源mysql 的，也可能是写死的数组，
     *              这个类干的活很多，帮你计算好了每个进程要干的任务数，任务编号范围
     *              其实你可以不用它，只关心有几个逻辑进程空间，自行处理每个进程要干的活
     * @param int $minTaskNum 最小任务个数 ，任务数很小，其实就没必要用多进程处理了
     * @param string $mode 工作模式支持（1=>pcntl，2=>swoole Process (单进程) ），默认使用 pcntl
     */
    public function __construct(int $workerNum = 1, int $totalTaskNum = 1, int $minTaskNum = 1, int $mode = 1)
    {
        $this->workerNum = $workerNum;
        $this->totalTaskNum = $totalTaskNum;
        $this->minTaskNum = $minTaskNum;
        $this->mode = $mode;
    }

    /**
     * 检测扩展是否符合要求
     */
    private function checkExtension()
    {
        switch ($this->mode) {
            case self::modePcntl:
                if (!\extension_loaded("pcntl")) {
                    exit("pcntl 扩展没有安装");
                }
            case self::modeSignleSwooleProcess:
                if (!\extension_loaded("swoole")) {
                    exit("swoole 扩展没有安装");
                }
            default :
                return;
        }
    }

    /**
     * 检测任务数，进程数设置是否合法
     */
    private function checkTaskNum()
    {
        if ($this->totalTaskNum < $this->workerNum) {
            exit("任务数不能小于进程个数");
        }
        if ($this->totalTaskNum < $this->minTaskNum) {
            exit("任务数小于最小任务个数设置");
        }
    }

    /**
     * 计算每个进程应该干的任务数量
     */
    private function perWorkPageShouldDoTaskNum()
    {
        $this->perWorkPageTaskNum = (int)($this->totalTaskNum / $this->workerNum);
    }

    //启动
    public function start()
    {
        //检测扩展
        $this->checkExtension();
        //检测任务数
        $this->checkTaskNum();
        //计算每个进程应该干的任务数量
        $this->perWorkPageShouldDoTaskNum();
        //fork 子进程个数,并设置任务回调函数，处理任务数量计算
        $this->forkProcessAndSetCallBack();
    }


    public function swooleSetCallBack()
    {
        echo 'Parent #' . getmypid() . ' exit' . PHP_EOL;
        for ($workPage = 1; $workPage <= $this->workerNum; $workPage++) {
            $process = new Swoole\Process(function () use ($workPage) {
                echo 'Child #' . getmypid() . " start and sleep {$workPage}s" . PHP_EOL;
                sleep($workPage);
                echo 'Child #' . getmypid() . ' exit' . PHP_EOL;
            });
            $process->start();
        }
        for ($i = $this->workerNum; $i--;) {
            Swoole\Process::wait(true);
//            $status = Swoole\Process::wait(true);
//            echo "Recycled #{$status['pid']}, code={$status['code']}, signal={$status['signal']}" . PHP_EOL;
        }

    }

    //fork 进程数，设置
    public function forkProcessAndSetCallBack()
    {
        // $workPage 工作空间, 0开始递增，pid的逻辑号
        for ($workPage = 1; $workPage <= $this->workerNum; $workPage++) {
            $pid = \pcntl_fork(); //创建成功会返回子进程id
            if ($pid < 0) {
                exit('子进程创建失败' . $pid);
            } else if ($pid > 0) {
                //父进程空间，返回子进程id,不做事情
                //echo "子进程{$pid} start".PHP_EOL;
            } else { //返回为0子进程空间
                // echo "i am child space,my id:{$workPage}".PHP_EOL;
                $this->setWorkCallBack($workPage);
                exit;
            }
        }
        //放在父进程空间，结束的子进程信息，阻塞状态,父进程等待子进程退出，避免僵尸进程
        $status = 0;
        for ($i = 1; $i <= $this->workerNum; $i++) {
            //$pid = \pcntl_wait($status);
            \pcntl_wait($status);
        }
    }


    /**
     * 根据工作空间编号获得相应任务
     * @param $workPage
     * @return array [$startTaskId 开始任务编号,$endTaskId 结束任务编号,$isLastWorkPage 是否是最后一个工作空间]
     */
    private function getWorkContent($workPage)
    {
        $perWorkPageTaskNum = $this->perWorkPageTaskNum;
        // 本工作空间，要处理的任务开始编号
        $startTaskId = ($workPage - 1) * $perWorkPageTaskNum + 1;
        // 本工作空间，要处理的任务结束编号
        $endTaskId = $workPage * $perWorkPageTaskNum;
        //最后一个工作空间，要考虑 数据新增情况，如mysql 任务，在脚本执行期间，新增数据
        $isLastWorkPage = false;
        if ($workPage == $this->workerNum) {
            $isLastWorkPage = true;
            $endTaskId = $this->totalTaskNum;
        }
        return [
            $startTaskId, $endTaskId, $isLastWorkPage
        ];
    }

    /**
     *  计算每个工作空间，应该处理的任务开始编号，任务结束编号,并设置回调函数，用于外部自定义处理任务
     * @param $workPage 工作进程逻辑空间编号
     * @param $pid 工作进程id
     */
    private function setWorkCallBack($workPage)
    {
        $pid = \getmypid();
        list($startTaskId, $endTaskId, $isLastWorkPage) = $this->getWorkContent($workPage);
        // onWork 回调函数， $startTaskId 开始任务编号，$endTaskId 结束任务编号,$isLastWorkPage是否最后一个工作空间,
        \call_user_func($this->onWork, $startTaskId, $endTaskId, $isLastWorkPage, $workPage, $pid);
    }
}

$workerNum = 4;      //工作进程个数
$totalTaskNum = 101;// 工作任务数  count()
$work = new WorkerProcess($workerNum, $totalTaskNum);
$work->onWork = function ($startTaskId, $endTaskId, $isLastWorkPage, $workPage, $pid) {
    //每个工作空间，如任务数较多，建议分页处理
    echo "工作空间编号{$workPage},pid:{$pid}, 负责任务编号{$startTaskId}-{$endTaskId}";
    if ($isLastWorkPage) {
        echo " 最后一个工作空间，需要跑脚本新增情况考虑，如select * from xxx where id > {$startTaskId}";
    }
    echo " 开始工作";
    echo PHP_EOL;
};
$work->start();



//use Swoole\Process;
//
//for ($n = 1; $n <= 3; $n++) {
//    $process = new Process(function () use ($n) {
//        echo 'Child #' . getmypid() . " start and sleep {$n}s" . PHP_EOL;
//        sleep($n);
//        echo 'Child #' . getmypid() . ' exit' . PHP_EOL;
//    });
//    $process->start();
//}
//for ($n = 3; $n--;) {
//    $status = Process::wait(true);
//    echo "Recycled #{$status['pid']}, code={$status['code']}, signal={$status['signal']}" . PHP_EOL;
//}
//echo 'Parent #' . getmypid() . ' exit' . PHP_EOL;