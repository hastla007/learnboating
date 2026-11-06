<?php

declare(strict_types=1);

namespace ContentBuild;

/** Sammelt Warnungen und übersprungene Datensätze für das Manifest. */
final class Report
{
    /** @var list<array{code:string,entity:string,source_id:?string,message:string}> */
    private array $warnings = [];

    /** @var list<array{entity:string,source_file:string,line:?int,source_id:?string,reason:string}> */
    private array $skipped = [];

    public function warn(string $code, string $entity, ?string $sourceId, string $message): void
    {
        $this->warnings[] = ['code' => $code, 'entity' => $entity, 'source_id' => $sourceId, 'message' => $message];
    }

    public function skip(string $entity, string $sourceFile, ?int $line, ?string $sourceId, string $reason): void
    {
        $this->skipped[] = ['entity' => $entity, 'source_file' => $sourceFile, 'line' => $line, 'source_id' => $sourceId, 'reason' => $reason];
    }

    /** @return list<array{code:string,entity:string,source_id:?string,message:string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<array{entity:string,source_file:string,line:?int,source_id:?string,reason:string}> */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
