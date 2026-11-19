<?php

namespace MagratheaExplorer\File;

/** Which addresses SafeFetcher may connect to. PublicAddressPolicy in production; tests allow loopback. */
interface AddressPolicy {

	/** @param string $ip an IPv4 or IPv6 address, as inet_pton() reads it */
	public function IsAllowed(string $ip): bool;

}
