<?php

namespace MagratheaExplorer;

use Magrathea\MagratheaApiControl;
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

	/**
	 * What GetPost() (when $_POST is empty) and GetPut() read: the body, parsed by RequestBody.
	 * JSON by media type, so `application/json; charset=utf-8` works; bad JSON is 40010; a body
	 * over 1 MB is 413. Overrides MagratheaPHP 3.0.0's, which only checks the Content-Type's last
	 * four characters (see RequestBody).
	 */
	public function GetPhpInput(): array {
		$contentType = $_SERVER["CONTENT_TYPE"] ?? null;
		// PHP has already parsed a multipart POST into $_POST and $_FILES, and php://input is
		// empty for it. An upload with no field but `file` gets here with an empty $_POST and the
		// whole upload as its Content-Length, which the 1 MB cap would refuse.
		if(RequestBody::IsMultipart($contentType)) return $_POST;
		$stream = fopen("php://input", "r");
		try {
			$raw = RequestBody::Read($stream, $_SERVER["CONTENT_LENGTH"] ?? null);
		} finally {
			fclose($stream);
		}
		return RequestBody::Parse($contentType, $raw);
	}

}
