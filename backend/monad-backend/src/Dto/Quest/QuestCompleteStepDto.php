<?php

namespace App\Dto\Quest;

use Symfony\Component\Validator\Constraints as Assert;

class QuestCompleteStepDto
{
    #[Assert\NotBlank(message: 'Step completion ID is required')]
    #[Assert\Uuid(message: 'Step completion ID must be a valid UUID')]
    public ?string $step_completion_id = null;

    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['completed', 'failed', 'skipped'],
        message: 'Status must be one of: completed, failed, skipped'
    )]
    public ?string $status = null;

    #[Assert\NotNull(message: 'Started at timestamp is required')]
    #[Assert\Type(type: 'string', message: 'Started at must be a string')]
    public ?string $started_at = null;

    #[Assert\NotNull(message: 'Completed at timestamp is required')]
    #[Assert\Type(type: 'string', message: 'Completed at must be a string')]
    public ?string $completed_at = null;

    #[Assert\Type(type: 'array', message: 'Step data must be an object')]
    public array $step_data = [];

    #[Assert\Valid]
    public ?QuestCompleteSkipRecordDto $skip_record = null;

    /**
     * Validate that timestamps are chronologically correct
     */
    #[Assert\Callback]
    public function validateTimestamps($context): void
    {
        if ($this->started_at && $this->completed_at) {
            try {
                $startedAt = new \DateTime($this->started_at);
                $completedAt = new \DateTime($this->completed_at);

                if ($startedAt >= $completedAt) {
                    $context->buildViolation('Started at timestamp must be before completed at timestamp')
                        ->atPath('started_at')
                        ->addViolation();
                }
            } catch (\Exception $e) {
                // Invalid date format will be caught by other validators
            }
        }
    }

    /**
     * Validate that skip_record is present for failed/skipped steps
     */
    #[Assert\Callback]
    public function validateSkipRecord($context): void
    {
        if (in_array($this->status, ['failed', 'skipped']) && !$this->skip_record) {
            $context->buildViolation('Skip record is required for failed or skipped steps')
                ->atPath('skip_record')
                ->addViolation();
        }
    }

    /**
     * Monotonic clock at this step, nanoseconds, as a string (IP-128).
     *
     * Same spelling as the ground-truth channel's `mono_ns` so one analysis join
     * covers both. Optional: older clients omit it and stay valid.
     */
    public ?string $mono_ns = null;
}
