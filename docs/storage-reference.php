<?php
declare(strict_types=1);

$entries = [
    'PBreeze\\Storage' => [
        'description' => 'Application-owned binary file storage with local, native SSH2 SFTP, AWS Signature V4 S3-compatible and Shared Key Azure Blob drivers. Use $app->storage, or construct independently. No Composer, static global default or implicit remote connections. Separate from logs, uploads, sessions and template storage.',
        'setup' => 'use PBreeze\\Storage;',
        'methods' => [
            '__construct' => ['Accept the application root, trusted storage configuration, optional private storage path and optional trusted HTTPS transport Closure for tests. Defaults local/16 MiB/30-second HTTP timeout. Validate configuration eagerly; create directories or connect only on operations. Local data lives under storagePath/files; remote resources must already be provisioned.', '$storage = new \\PBreeze\\Storage($root, ["driver" => "local"]);'],
            'put' => ['Store binary string content, including empty files, replacing an existing key. Returns void; failures throw. Enforce configured max_bytes, common portable relative keys and backend protections. Local replacement uses a same-directory temporary file/rename; SFTP stages a recoverable backup for overwrite, not atomic isolation. Cloud writes use one bounded object/blob request without automatic retries.', '$app->storage->put("documents/test.pdf", $content);'],
            'get' => ['Return exact binary string content. Missing files throw RuntimeException; never return empty content as a missing/error fallback. Enforce bounded reads of max_bytes (default 16 MiB). For large files use an application-level chunk/workflow; no streaming public API is introduced.', '$content = $app->storage->get("documents/test.pdf");'],
            'exists' => ['Return true for an accessible file/object, false for absence. Invalid keys, symlinks, incompatible local/SFTP types, permissions, auth, TLS, transport and non-404 service failures throw. Cloud 404 responses are treated as missing; keep the bucket/container provisioned.', '$exists = $app->storage->exists("documents/test.pdf");'],
            'delete' => ['Delete the key; return true when deletion is acknowledged and false for absence. Does not delete parent directories, create buckets, or hide errors. S3 first probes HEAD (requires read permission); its return is not a concurrent-writer guarantee and versioned buckets may retain old versions. Azure removes the blob including snapshots; retained versions/soft deletes follow provider policy.', '$deleted = $app->storage->delete("documents/test.pdf");'],
            'key' => ['Internal shared key validator. Requires relative UTF-8 keys up to 1024 bytes, segments up to 255 bytes, no dot/empty/hidden segments, backslashes, percent escapes, fragment/reserved characters, controls, trailing dots/spaces or Windows device names. Throws InvalidArgumentException, does not normalize ambiguous input.', '\\PBreeze\\Storage::key("documents/report.json");'],
        ],
    ],
    'PBreeze\\Storage\\HttpClient' => [
        'description' => 'Internal shared native HTTPS transport for S3/Azure. TLS >=1.2 with certificate/hostname verification, no redirects, bounded response accumulation, finite timeout and safe failure messages without provider bodies or credential-bearing URLs. Optional Closure is a trusted test seam, not untrusted application input.',
        'setup' => '',
        'methods' => [
            '__construct' => ['Accept HTTP timeout 1..300 seconds, max bytes 1..256 MiB, optional CA file and trusted replacement Closure. Does not connect. Remote operations require ext-curl unless replaced in isolated tests.', '$http = new \\PBreeze\\Storage\\HttpClient(30, 16777216);'],
            'endpoint' => ['Validate and normalize a trusted HTTPS origin. Reject HTTP, URL credentials, paths, query and fragment. Endpoints are operator-controlled infrastructure and may be private-network services; never accept them from request data.', '$origin = \\PBreeze\\Storage\\HttpClient::endpoint("https://s3.example.test");'],
            'request' => ['Internal PUT/GET/HEAD/DELETE request with already-signed headers and bounded content. Return status/body shape; native transport errors/oversize throw without exposing response details. Drivers interpret HTTP statuses. Validate methods, headers and credential-free HTTPS URLs; disable redirects and do not retry mutations.', '$result = $http->request("HEAD", $trustedObjectUrl, $signedHeaders);'],
        ],
    ],
];

foreach ([
    'LocalDriver' => [
        'Native private files directory with ancestor and key symlink checks, portable names, 0700 directories and 0600 files on POSIX. Windows uses inherited NTFS ACLs. Trusted exclusive service ownership is required: pathname checks do not defend against hostile concurrent OS writers. Root confinement reuses Filesystem.',
        'Accept application root, private storage path and maximum byte size. Internal driver; prefer Storage for validated configuration and redacted native warnings.',
        '$driver = new \\PBreeze\\Storage\\LocalDriver($root, "storage", 16777216);',
    ],
    'SftpDriver' => [
        'Native ext-ssh2 driver with pinned SHA-1 host-key identity, password or RSA PEM key-file authentication, existing absolute remote root, directory listings for reliable missing-vs-failure distinction, and lstat symlink refusal. RSA PEM avoids unverified OpenSSH/ECDSA key compatibility. SHA-1 pin format is imposed by ext-ssh2; independently verify it, and use server-side confinement. No guaranteed per-operation deadline is available through this extension.',
        'Accept trusted SFTP configuration and maximum byte size. Connect lazily, verify host before authentication, and recheck root ancestors on operations. Remote root must exist; children are created as needed. Password and key-file modes are mutually exclusive.',
        '$driver = new \\PBreeze\\Storage\\SftpDriver($sftpConfig, 16777216);',
    ],
    'S3Driver' => [
        'S3-compatible driver using path-style object URLs, explicit region and AWS Signature V4/SHA-256 via native HTTPS. Supports access/secret keys and optional session token; no credential discovery/refresh, multipart uploads, signed URLs, bucket creation or ACL changes. Buckets and required permissions must be provisioned externally.',
        'Accept trusted S3 endpoint/bucket/region/credentials configuration and shared HttpClient. No connection occurs in construction; prefer $app->storage.',
        '$driver = new \\PBreeze\\Storage\\S3Driver($s3Config, $http);',
    ],
    'AzureDriver' => [
        'Azure Blob BlockBlob driver using Shared Key/HMAC-SHA256 with base64 account key and REST version 2023-11-03. Defaults to the standard account HTTPS origin; supports trusted sovereign/custom origin overrides. No SAS/Entra/managed-identity discovery, container creation, chunked block uploads or access-policy changes.',
        'Accept trusted account/container/key/optional endpoint configuration and shared HttpClient. Account key must decode to 64 bytes; construction is connection-free. Prefer least-privilege operational isolation and rotate keys externally.',
        '$driver = new \\PBreeze\\Storage\\AzureDriver($azureConfig, $http);',
    ],
] as $class => [$description, $construction, $example]) {
    $entries['PBreeze\\Storage\\' . $class] = [
        'description' => $description,
        'setup' => '',
        'methods' => [
            '__construct' => [$construction, $example],
            'put' => ['Driver-level binary write with shared key validation; called by Storage after byte-bound checks. Failures throw, never return a false success. Local stages a temporary file; SFTP uses a recoverable previous-file backup during overwrite; object drivers send signed PUT.', '$driver->put("documents/report.bin", $content);'],
            'get' => ['Return file/object bytes with bounded reads. Missing files and operational failures throw; empty content is a valid stored value.', '$content = $driver->get("documents/report.bin");'],
            'exists' => ['Check the file/object key with shared validation. Return false only for missing entries or cloud HTTP 404; permission/network/auth and unsafe filesystem paths throw.', '$exists = $driver->exists("documents/report.bin");'],
            'delete' => ['Delete one file/object, returning false for missing and true for acknowledged deletion. Retain parent directories and throw on failures. S3 checks HEAD first; Azure includes snapshots.', '$deleted = $driver->delete("documents/report.bin");'],
        ],
    ];
}

return $entries;
