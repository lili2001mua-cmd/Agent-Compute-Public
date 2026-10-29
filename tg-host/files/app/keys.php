<?php
// sha256 of the door keys — the keys themselves are never in this public repo.
// read: kept in the PRIVATE repo (Agent-Project-Backup, Sys.registry/tg-host-read-key.secret) — user decision 1405-08-07.
// admin: lives only in the Claude cloud session that is doing admin work (never in any repo); a new session
//        that needs admin jobs issues a fresh key and replaces this hash through a user-pinned SHA.
return [
    'read'  => 'b9757d76486f0fd2b99f30f9a777a290ec4a41419676e177afc85d84d8deb9bd',
    // chain: kept in the PRIVATE repo next to the read key; opens only chain_ops on host-owned allowlisted chats (chain-host-migration ک1-ک6).
    'chain' => '7a3059c254aca11146d56243567b150d60bf8a892b2af39457d4af51d8b1b1bb',
    'admin' => '8f7302974611427664171ec2a6c30152156540e8d7d96711a7dc52566f490083',
];
