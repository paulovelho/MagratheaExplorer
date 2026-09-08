<?php

namespace MagratheaExplorer\Storage;

use Magrathea2\Singleton;

class StorageFactory extends Singleton {

	private ?StorageAdapter $adapter = null;

	public function Get(): StorageAdapter {
		if($this->adapter !== null) return $this->adapter;
		$driver = StorageConfig::GetDriver();
		$this->adapter = match($driver) {
			"s3" => new S3StorageAdapter(),
			"local" => new LocalStorageAdapter(),
			default => throw new StorageException("unknown storage driver: ".$driver),
		};
		return $this->adapter;
	}

}
