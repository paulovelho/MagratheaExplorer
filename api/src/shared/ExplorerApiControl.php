<?php

namespace MagratheaExplorer;

use Magrathea2\MagratheaApiControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;

/**
 * Common base for every business controller (Key/Folder/File APIs). Gives every route
 * action a cheap `GetRequestKey()` that re-resolves the bearer key -- the route's own
 * auth gate (Key/KeyAuthControl.php) already validated it once, but the framework's
 * declarative route auth doesn't pass identity through, so this is a second, equally
 * cheap indexed lookup rather than shared state.
 */
abstract class ExplorerApiControl extends MagratheaApiControl {

	private ?Key $requestKey = null;

	public function GetRequestKey(): Key {
		if($this->requestKey !== null) return $this->requestKey;
		$token = $this->GetAuthorizationToken();
		$this->requestKey = KeyControl::ResolveFromBearer($token);
		return $this->requestKey;
	}

}
