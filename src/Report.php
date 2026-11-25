<?php

namespace IdeiaCv\BugCatcher;

use Throwable;

/**
 * One error about to be sent. Callbacks receive it and may change it; returning false from a
 * callback cancels the report. The method names follow Bugsnag\Report, so callbacks written for
 * Bugsnag keep working after the switch.
 */
class Report
{
    public const SEVERITIES = ['error', 'warning', 'info'];

    protected ?Throwable $originalError = null;

    protected string $name;

    protected string $message;

    protected string $severity = 'warning';

    protected string $severityReason = 'handledException';

    protected bool $unhandled = false;

    /** @var list<array<string, mixed>> */
    protected array $stacktrace = [];

    /** @var list<array{class: string, message: string, stacktrace: list<array<string, mixed>>}> */
    protected array $previous = [];

    protected array $metaData = [];

    protected array $user = [];

    protected ?string $context = null;

    protected ?string $groupingHash = null;

    protected ?string $appVersion = null;

    protected ?string $releaseStage = null;

    final protected function __construct(string $name, string $message)
    {
        $this->name = $name;
        $this->message = $message;
    }

    public static function fromThrowable(Throwable $throwable, StacktraceBuilder $stacktraces): static
    {
        $report = new static(get_class($throwable), $throwable->getMessage());
        $report->originalError = $throwable;
        $report->stacktrace = $stacktraces->fromThrowable($throwable);

        $previous = $throwable->getPrevious();
        for ($i = 0; $previous !== null && $i < 10; $i++, $previous = $previous->getPrevious()) {
            $report->previous[] = [
                'class' => get_class($previous),
                'message' => $previous->getMessage(),
                'stacktrace' => $stacktraces->fromThrowable($previous),
            ];
        }

        return $report;
    }

    /** @param list<array<string, mixed>> $stacktrace */
    public static function fromNamedError(string $name, string $message, array $stacktrace): static
    {
        $report = new static($name, $message);
        $report->stacktrace = $stacktrace;

        return $report;
    }

    public function getOriginalError(): ?Throwable
    {
        return $this->originalError;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setMessage(?string $message): static
    {
        $this->message = (string) $message;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setSeverity(?string $severity): static
    {
        $this->severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'error';

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverityReason(string $reason): static
    {
        $this->severityReason = $reason;

        return $this;
    }

    public function getSeverityReason(): string
    {
        return $this->severityReason;
    }

    public function setUnhandled(bool $unhandled): static
    {
        $this->unhandled = $unhandled;

        return $this;
    }

    public function isUnhandled(): bool
    {
        return $this->unhandled;
    }

    /**
     * Metadata shown in tabs on the issue page: ['tab' => ['key' => 'value']].
     * With $merge (the default) existing tabs are merged recursively.
     */
    public function setMetaData(array $metaData, bool $merge = true): static
    {
        $this->metaData = $merge ? array_replace_recursive($this->metaData, $metaData) : $metaData;

        return $this;
    }

    public function addMetaData(array $metaData): static
    {
        return $this->setMetaData($metaData, true);
    }

    public function getMetaData(): array
    {
        return $this->metaData;
    }

    /** @param array{id?: mixed, email?: mixed, name?: mixed} $user */
    public function setUser(array $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): array
    {
        return $this->user;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    /** Events with the same grouping hash always land on the same issue. */
    public function setGroupingHash(?string $groupingHash): static
    {
        $this->groupingHash = $groupingHash;

        return $this;
    }

    public function getGroupingHash(): ?string
    {
        return $this->groupingHash;
    }

    public function setAppVersion(?string $appVersion): static
    {
        $this->appVersion = $appVersion;

        return $this;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function setReleaseStage(?string $releaseStage): static
    {
        $this->releaseStage = $releaseStage;

        return $this;
    }

    public function getReleaseStage(): ?string
    {
        return $this->releaseStage;
    }

    public function getStacktrace(): array
    {
        return $this->stacktrace;
    }

    public function getPrevious(): array
    {
        return $this->previous;
    }
}
