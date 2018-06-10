<?php

declare(strict_types=1);


namespace raklib\scheduler;


class TaskHandler{

	/** @var Task */
	protected $task;

	/** @var int */
	protected $taskId;

	/** @var int */
	protected $delay;

	/** @var int */
	protected $period;

	/** @var int */
	protected $nextRun;

	/** @var bool */
	protected $cancelled = false;


	/**
	 * @param string $timingName
	 * @param Task   $task
	 * @param int    $taskId
	 * @param int    $delay
	 * @param int    $period
	 */
	public function __construct(Task $task, int $taskId, int $delay = -1, int $period = -1){
		$this->task = $task;
		$this->taskId = $taskId;
		$this->delay = $delay;
		$this->period = $period;
		$this->task->setHandler($this);
	}

	/**
	 * @return bool
	 */
	public function isCancelled() : bool{
		return $this->cancelled;
	}

	/**
	 * @return int
	 */
	public function getNextRun() : int{
		return $this->nextRun;
	}

	/**
	 * @param int $ticks
	 */
	public function setNextRun(int $ticks){
		$this->nextRun = $ticks;
	}

	/**
	 * @return int
	 */
	public function getTaskId() : int{
		return $this->taskId;
	}

	/**
	 * @return Task
	 */
	public function getTask() : Task{
		return $this->task;
	}

	/**
	 * @return int
	 */
	public function getDelay() : int{
		return $this->delay;
	}

	/**
	 * @return bool
	 */
	public function isDelayed() : bool{
		return $this->delay > 0;
	}

	/**
	 * @return bool
	 */
	public function isRepeating() : bool{
		return $this->period > 0;
	}

	/**
	 * @return int
	 */
	public function getPeriod() : int{
		return $this->period;
	}

	/**
	 * WARNING: Do not use this, it's only for internal use.
	 * Changes to this function won't be recorded on the version.
	 */
	public function cancel(){
		try{
			if(!$this->isCancelled()){
				$this->task->onCancel();
			}
		}catch(\Throwable $e){
			var_dump($e);
		}finally{
			$this->remove();
		}
	}

	public function remove(){
		$this->cancelled = true;
		$this->task->setHandler(null);
	}

	/**
	 * @param int $currentTick
	 */
	public function run(int $currentTick){
		$this->task->onRun($currentTick);
	}

	/**
	 * @return string
	 */
	public function getTaskName() : string{
		return get_class($this->task);
	}
}