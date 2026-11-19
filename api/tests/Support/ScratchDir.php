<?php

namespace MagratheaExplorer\Tests\Support;

/** A fresh, empty directory under the system temp dir, and its recursive removal (call it in tearDown). */
class ScratchDir {

	public static function Create(string $prefix = "explorer_test_"): string {
		$dir = sys_get_temp_dir()."/".$prefix.bin2hex(random_bytes(6));
		mkdir($dir, 0700);
		return $dir;
	}

	public static function Remove(string $dir): void {
		if(!is_dir($dir)) return;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($items as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}

}
