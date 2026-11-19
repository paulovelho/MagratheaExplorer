<?php

namespace MagratheaExplorer\File;

/**
 * A and AAAA records through dns_get_record(): the system's resolver (resolv.conf), not
 * nsswitch, so /etc/hosts doesn't count. That's on purpose: SafeFetcher connects only to the
 * addresses this returns (CURLOPT_RESOLVE), and a hosts-file entry is exactly the kind of local
 * override a fetch for a caller mustn't follow. It has no timeout of its own: a stalled resolver
 * holds the request until PHP's or the proxy's limit (Tier 2 plan §14).
 */
final class DnsHostResolver implements HostResolver {

	public function Resolve(string $host): ?array {
		// false = the lookup failed (TRY_AGAIN, NO_RECOVERY); a name that doesn't exist (NXDOMAIN)
		// or has no address records is an empty array
		$records = @dns_get_record($host, DNS_A | DNS_AAAA);
		if(!is_array($records)) return null;
		$addresses = [];
		foreach($records as $record) {
			// CNAMEs are followed by the resolver; only the final A/AAAA answers count
			if(($record["type"] ?? "") === "A" && isset($record["ip"])) $addresses[] = (string)$record["ip"];
			if(($record["type"] ?? "") === "AAAA" && isset($record["ipv6"])) $addresses[] = (string)$record["ipv6"];
		}
		return array_values(array_unique($addresses));
	}

}
