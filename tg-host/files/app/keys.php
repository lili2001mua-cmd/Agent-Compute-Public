<?php
// sha256 of the door keys — the keys themselves are never in this public repo.
// read: held only by the client side (env TG_HOST_READ_KEY or ~/.config/tg-host/read-key), never in a repo.
// admin: not issued yet (phase 2 decides where it lives); empty = no admin access possible.
return [
    'read'  => 'b9757d76486f0fd2b99f30f9a777a290ec4a41419676e177afc85d84d8deb9bd',
    'admin' => '',
];
