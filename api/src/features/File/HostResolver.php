<?php

namespace MagratheaExplorer\File;

/** A DNS name's addresses, for SafeFetcher. DnsHostResolver in production; tests map names to loopback. */
interface HostResolver {

	/**
	 * @return ?string[] every IPv4 and IPv6 address the name resolves to; [] when it has none (no
	 *                   such name, or no A/AAAA record); null when the lookup itself failed (a
	 *                   resolver timeout or SERVFAIL), which a retry may get past
	 */
	public function Resolve(string $host): ?array;

}
