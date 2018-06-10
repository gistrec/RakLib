<?php

namespace raklib\scheduler;

class TestTask extends Task {
	public function onRun(int $currentTick) {
		echo "Таск выполнился";
	}
}