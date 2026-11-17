<?php

namespace MagratheaExplorer;

/**
 * For AdminFeature actions that change data. The admin checks its CSRF token on POST
 * only, so a GET (an <img src>, a prefetch, a pasted link) must never reach a write:
 * it answers 405 instead. AdminCrudObject has this guard built in; AdminFeature doesn't.
 * Protected, so the admin router never dispatches it as an action.
 */
trait AdminPostOnly {

	protected function RefuseIfNotPost(): void {
		if(($_SERVER["REQUEST_METHOD"] ?? "") === "POST") return;
		http_response_code(405);
		header("Allow: POST");
		echo "This action requires a POST request.";
		exit;
	}

}
