<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\User;
use App\Enum\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private const string NOW = '2026-09-30 09:00:00';

    public function testSavingDoesNotScheduleAnyReminder(): void
    {
        $application = $this->application();

        self::assertSame(PipelineStatus::Saved, $application->getPipelineStatus());
        self::assertNull($application->getFollowUpAt());
        self::assertFalse($application->isFollowUpDue($this->now()));
    }

    public function testBecomingAppliedStartsTheFollowUpClock(): void
    {
        $application = $this->application();

        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());

        self::assertEquals($this->now(), $application->getAppliedAt());
        self::assertEquals($this->now()->modify('+7 days'), $application->getFollowUpAt());
        self::assertSame(0, $application->getRelanceCount());
    }

    public function testFollowUpIsOnlyDueOnceItsDatePasses(): void
    {
        $application = $this->application();
        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());

        self::assertFalse($application->isFollowUpDue($this->now()->modify('+6 days')));
        self::assertTrue($application->isFollowUpDue($this->now()->modify('+7 days')));
    }

    public function testSendingARelanceSchedulesTheNextOneUntilTheMaximumIsReached(): void
    {
        $application = $this->application();
        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());

        $application->recordRelanceSent(7, 2, $this->now()->modify('+7 days'));
        self::assertSame(1, $application->getRelanceCount());
        self::assertEquals($this->now()->modify('+14 days'), $application->getFollowUpAt());

        $application->recordRelanceSent(7, 2, $this->now()->modify('+14 days'));
        self::assertSame(2, $application->getRelanceCount());
        self::assertNull($application->getFollowUpAt(), 'the second relance is the last one');
    }

    public function testAnInterviewCancelsPendingReminders(): void
    {
        $application = $this->application();
        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());

        $application->changeStatus(PipelineStatus::Interview, 7, $this->now()->modify('+3 days'));

        self::assertNull($application->getFollowUpAt());
        self::assertFalse($application->isFollowUpDue($this->now()->modify('+30 days')));
    }

    public function testSnoozingPushesTheReminderWithoutCountingAsARelance(): void
    {
        $application = $this->application();
        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());

        $application->snoozeUntil($this->now()->modify('+21 days'));

        self::assertSame(0, $application->getRelanceCount());
        self::assertFalse($application->isFollowUpDue($this->now()->modify('+14 days')));
        self::assertTrue($application->isFollowUpDue($this->now()->modify('+21 days')));
    }

    public function testClosingTheFollowUpKeepsTheApplicationInItsCurrentStatus(): void
    {
        $application = $this->application();
        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());

        $application->closeFollowUp();

        self::assertSame(PipelineStatus::Applied, $application->getPipelineStatus());
        self::assertNull($application->getFollowUpAt());
    }

    public function testReapplyingDoesNotResetTheOriginalApplicationDate(): void
    {
        $application = $this->application();
        $application->changeStatus(PipelineStatus::Applied, 7, $this->now());
        $application->changeStatus(PipelineStatus::Rejected, 7, $this->now()->modify('+2 days'));

        $application->changeStatus(PipelineStatus::Applied, 7, $this->now()->modify('+30 days'));

        self::assertEquals($this->now(), $application->getAppliedAt(), 'the first application date is history');
        self::assertEquals(
            $this->now()->modify('+37 days'),
            $application->getFollowUpAt(),
            'the reminder counts from the moment the status was set, not from the original date',
        );
    }

    private function application(): Application
    {
        $owner = new User('owner@example.test');

        return new Application($owner, new Company('Acme'));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
