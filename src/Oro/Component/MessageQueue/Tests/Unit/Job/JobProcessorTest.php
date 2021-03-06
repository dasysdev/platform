<?php

namespace Oro\Component\MessageQueue\Tests\Unit\Job;

use Oro\Component\MessageQueue\Job\DuplicateJobException;
use Oro\Component\MessageQueue\Job\Job;
use Oro\Component\MessageQueue\Job\JobProcessor;
use Oro\Component\MessageQueue\Job\JobStorage;
use Oro\Component\MessageQueue\Provider\JobConfigurationProviderInterface;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class JobProcessorTest extends \PHPUnit\Framework\TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject|JobStorage */
    private $jobStorage;

    /** @var JobProcessor */
    private $jobProcessor;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->jobStorage = $this->createMock(JobStorage::class);

        $this->jobProcessor = new JobProcessor($this->jobStorage);
    }

    public function testCreateRootJobShouldThrowIfOwnerIdIsEmpty()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OwnerId must not be empty');

        $this->jobProcessor->findOrCreateRootJob(null, 'job-name', true);
    }

    public function testCreateRootJobShouldThrowIfNameIsEmpty()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job name must not be empty');

        $this->jobProcessor->findOrCreateRootJob('owner-id', null, true);
    }

    public function testShouldCreateRootJobAndReturnIt()
    {
        $job = new Job();

        $this->jobStorage->expects(self::once())
            ->method('createJob')
            ->willReturn($job);
        $this->jobStorage->expects(self::once())
            ->method('saveJob')
            ->with(self::identicalTo($job));
        $this->jobStorage->expects(self::once())
            ->method('findRootJobByOwnerIdAndJobName')
            ->with('owner-id', 'job-name');

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertSame($job, $result);
        self::assertEquals(Job::STATUS_NEW, $job->getStatus());
        self::assertLessThanOrEqual(new \DateTime(), $job->getCreatedAt());
        self::assertLessThanOrEqual(new \DateTime(), $job->getStartedAt());
        self::assertNull($job->getStoppedAt());
        self::assertEquals('job-name', $job->getName());
        self::assertEquals('owner-id', $job->getOwnerId());
    }

    public function testShouldCatchDuplicateJobAndReturnNull()
    {
        $job = new Job();

        $this->jobStorage->expects(self::once())
            ->method('createJob')
            ->willReturn($job);
        $this->jobStorage->expects(self::once())
            ->method('saveJob')
            ->with(self::identicalTo($job))
            ->willThrowException(new DuplicateJobException());
        $this->jobStorage->expects(self::once())
            ->method('findRootJobByOwnerIdAndJobName')
            ->with('owner-id', 'job-name');

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertNull($result);
    }

    public function testFindOrCreateRootJobFindJobAndReturn()
    {
        $job = new Job();

        $this->jobStorage->expects(self::never())
            ->method('createJob');
        $this->jobStorage->expects(self::never())
            ->method('saveJob');
        $this->jobStorage->expects(self::once())
            ->method('findRootJobByOwnerIdAndJobName')
            ->with('owner-id', 'job-name')
            ->willReturn($job);

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertSame($job, $result);
    }

    public function testShouldCatchDuplicateCheckIfItIsStaleAndChangeStatus()
    {
        $job = new Job();
        $job->setChildJobs([]);

        $jobConfigurationProvider = $this->configureBaseMocksForStaleJobsCases($job, 0, $job);
        $this->jobProcessor->setJobConfigurationProvider($jobConfigurationProvider);

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertSame($job, $result);
    }

    public function testFindOrCreateReturnsNullIfRootJobInActiveStatusCannotBeFound()
    {
        $job = new Job();
        $job->setChildJobs([]);

        $jobConfigurationProvider= $this->configureBaseMocksForStaleJobsCases($job);
        $this->jobProcessor->setJobConfigurationProvider($jobConfigurationProvider);

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertNull($result);
    }

    public function testFindOrCreateReturnsNullIfRootJobIsNotStaleYet()
    {
        $job = new Job();
        $job->setChildJobs([]);

        $jobConfigurationProvider = $this->configureBaseMocksForStaleJobsCases($job, 100, $job);
        $this->jobProcessor->setJobConfigurationProvider($jobConfigurationProvider);

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertNull($result);
    }

    public function testFindOrCreateReturnsNullIfRootJobStaleByTimeButHaveNotStartedChild()
    {
        $job = new Job();
        $childJob = new Job();
        $childJob->setStatus(Job::STATUS_NEW);
        $job->setChildJobs([$childJob]);

        $jobConfigurationProvider = $this->configureBaseMocksForStaleJobsCases($job, 0, $job);
        $this->jobProcessor->setJobConfigurationProvider($jobConfigurationProvider);

        $result = $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertNull($result);
    }

    public function testStaleRootJobAndChildrenWillChangeStatusForRootAndRunningChildren()
    {
        $rootJob = new Job();
        $rootJob->setId(1);
        $childJob1 = new Job();
        $childJob1->setId(11);
        $childJob1->setStatus(Job::STATUS_RUNNING);
        $childJob1->setRootJob($rootJob);
        $childJob2 = new Job();
        $childJob2->setId(12);
        $childJob2->setStatus(Job::STATUS_SUCCESS);
        $childJob2->setRootJob($rootJob);
        $rootJob->addChildJob($childJob1);
        $rootJob->addChildJob($childJob2);

        $jobConfigurationProvider = $this->configureBaseMocksForStaleJobsCases($rootJob, 0, $rootJob);
        $this->jobProcessor->setJobConfigurationProvider($jobConfigurationProvider);

        $this->jobStorage->method('findJobById')
            ->withConsecutive([1], [11], [12])
            ->willReturnOnConsecutiveCalls($rootJob, $childJob1, $childJob2);

        $this->jobProcessor->findOrCreateRootJob('owner-id', 'job-name', true);

        self::assertSame(Job::STATUS_STALE, $rootJob->getStatus());
        self::assertSame(Job::STATUS_STALE, $childJob1->getStatus());
        self::assertSame(Job::STATUS_SUCCESS, $childJob2->getStatus());
    }

    public function testCreateChildJobShouldThrowIfNameIsEmpty()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job name must not be empty');

        $this->jobProcessor->findOrCreateChildJob(null, new Job());
    }

    public function testCreateChildJobShouldFindAndReturnAlreadyCreatedJob()
    {
        $job = new Job();
        $job->setId(123);

        $this->jobStorage->expects(self::never())
            ->method('createJob');
        $this->jobStorage->expects(self::never())
            ->method('saveJob');
        $this->jobStorage->expects(self::once())
            ->method('findChildJobByName')
            ->with('job-name', self::identicalTo($job))
            ->willReturn($job);
        $this->jobStorage->expects(self::once())
            ->method('findJobById')
            ->with(123)
            ->willReturn($job);

        $result = $this->jobProcessor->findOrCreateChildJob('job-name', $job);

        self::assertSame($job, $result);
    }

    public function testCreateChildJobShouldCreateAndSaveJobAndPublishRecalculateRootMessage()
    {
        $job = new Job();
        $job->setId(12345);

        $this->jobStorage->expects(self::once())
            ->method('createJob')
            ->willReturn($job);
        $this->jobStorage->expects(self::once())
            ->method('saveJob')
            ->with(self::identicalTo($job));
        $this->jobStorage->expects(self::once())
            ->method('findChildJobByName')
            ->with('job-name', self::identicalTo($job))
            ->willReturn(null);
        $this->jobStorage->expects(self::once())
            ->method('findJobById')
            ->with(12345)
            ->willReturn($job);

        $result = $this->jobProcessor->findOrCreateChildJob('job-name', $job);

        self::assertSame($job, $result);
        self::assertEquals(Job::STATUS_NEW, $job->getStatus());
        self::assertLessThanOrEqual(new \DateTime(), $job->getCreatedAt());
        self::assertNull($job->getStartedAt());
        self::assertNull($job->getStoppedAt());
        self::assertEquals('job-name', $job->getName());
        self::assertNull($job->getOwnerId());
    }

    public function testStartChildJobShouldThrowIfRootJob()
    {
        $rootJob = new Job();
        $rootJob->setId(12345);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Can\'t start root jobs. id: "12345"');

        $this->jobProcessor->startChildJob($rootJob);
    }

    public function testStartChildJobShouldThrowIfJobHasNotNewStatus()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_CANCELLED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Can start only new jobs: id: "12345", status: "oro.message_queue_job.status.cancelled"'
        );

        $this->jobProcessor->startChildJob($job);
    }

    /**
     * @return array
     */
    public function getStatusThatCanRun()
    {
        return [
            [Job::STATUS_NEW],
            [Job::STATUS_FAILED_REDELIVERED],
        ];
    }

    /**
     * @param string $jobStatus
     * @dataProvider getStatusThatCanRun
     *
     * @param string $jobStatus
     */
    public function testStartJobShouldUpdateJobWithRunningStatusAndStartAtTime($jobStatus)
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus($jobStatus);

        $this->jobStorage->expects(self::any())
            ->method('saveJob')
            ->with(self::isInstanceOf(Job::class));

        $this->jobProcessor->startChildJob($job);

        self::assertEquals(Job::STATUS_RUNNING, $job->getStatus());
        self::assertLessThanOrEqual(new \DateTime(), $job->getStartedAt());
    }

    public function testSuccessChildJobShouldThrowIfRootJob()
    {
        $rootJob = new Job();
        $rootJob->setId(12345);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Can\'t success root jobs. id: "12345"');

        $this->jobProcessor->successChildJob($rootJob);
    }

    public function testSuccessChildJobShouldThrowIfJobHasNotRunningStatus()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_CANCELLED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Can success only running jobs. id: "12345", status: "oro.message_queue_job.status.cancelled"'
        );

        $this->jobProcessor->successChildJob($job);
    }

    public function testSuccessJobShouldUpdateJobWithSuccessStatusAndStopAtTime()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_RUNNING);

        $this->jobStorage->expects(self::any())
            ->method('saveJob')
            ->with(self::isInstanceOf(Job::class));

        $this->jobProcessor->successChildJob($job);

        self::assertEquals(Job::STATUS_SUCCESS, $job->getStatus());
        self::assertLessThanOrEqual(new \DateTime(), $job->getStoppedAt());
    }

    public function testFailChildJobShouldThrowIfRootJob()
    {
        $rootJob = new Job();
        $rootJob->setId(12345);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Can\'t fail root jobs. id: "12345"');

        $this->jobProcessor->failChildJob($rootJob);
    }

    public function testFailChildJobShouldThrowIfJobHasNotRunningStatus()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_CANCELLED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Can fail only running jobs. id: "12345", status: "oro.message_queue_job.status.cancelled"'
        );

        $this->jobProcessor->failChildJob($job);
    }

    public function testFailJobShouldUpdateJobWithFailStatusAndStopAtTime()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_RUNNING);

        $this->jobStorage->expects(self::exactly(2))
            ->method('saveJob')
            ->with(self::isInstanceOf(Job::class));

        $this->jobProcessor->failChildJob($job);

        self::assertEquals(Job::STATUS_FAILED, $job->getStatus());
        $stoppedAt = $job->getStoppedAt();
        self::assertInstanceOf(\DateTime::class, $stoppedAt);
        self::assertLessThanOrEqual(new \DateTime(), $stoppedAt);
    }

    public function testCancelChildJobShouldThrowIfRootJob()
    {
        $rootJob = new Job();
        $rootJob->setId(12345);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Can\'t cancel root jobs. id: "12345"');

        $this->jobProcessor->cancelChildJob($rootJob);
    }

    public function testCancelChildJobShouldThrowIfJobHasNotNewOrRunningStatus()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_CANCELLED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Can cancel only new or running jobs. id: "12345", status: "oro.message_queue_job.status.cancelled"'
        );

        $this->jobProcessor->cancelChildJob($job);
    }

    public function testCancelJobShouldUpdateJobWithCancelStatusAndStoppedAtTimeAndStartedAtTime()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_NEW);

        $this->jobStorage->expects(self::any())
            ->method('saveJob')
            ->with(self::isInstanceOf(Job::class));

        $this->jobProcessor->cancelChildJob($job);

        self::assertEquals(Job::STATUS_CANCELLED, $job->getStatus());
        self::assertLessThanOrEqual(new \DateTime(), $job->getStoppedAt());
        self::assertLessThanOrEqual(new \DateTime(), $job->getStartedAt());
    }

    public function testInterruptRootJobShouldThrowIfNotRootJob()
    {
        $notRootJob = new Job();
        $notRootJob->setId(123);
        $notRootJob->setRootJob(new Job());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Can interrupt only root jobs. id: "123"');

        $this->jobProcessor->interruptRootJob($notRootJob);
    }


    public function testInterruptRootJobShouldCancelChildrenJobIfRunWithForce()
    {
        $rootJob = new Job();
        $rootJob->setId(123);
        $childJob = new Job();
        $childJob->setId(1234);
        $childJob->setStatus(Job::STATUS_NEW);
        $childJob->setRootJob($rootJob);
        $rootJob->setChildJobs([$childJob]);

        $this->jobStorage->expects(self::at(0))
            ->method('saveJob')
            ->willReturnCallback(function (Job $job, $callback) {
                $callback($job);
            });
        $this->jobStorage->expects(self::at(1))
            ->method('saveJob')
            ->with($rootJob);

        $this->jobProcessor->interruptRootJob($rootJob, true);

        self::assertTrue($rootJob->isInterrupted());
        self::assertLessThanOrEqual(new \DateTime(), $rootJob->getStoppedAt());
        self::assertEquals($childJob->getStatus(), Job::STATUS_CANCELLED);
    }

    public function testInterruptRootJobShouldDoNothingIfAlreadyInterrupted()
    {
        $rootJob = new Job();
        $rootJob->setId(123);
        $rootJob->setInterrupted(true);

        $this->jobStorage->expects(self::never())
            ->method('saveJob');

        $this->jobProcessor->interruptRootJob($rootJob);
    }

    public function testInterruptRootJobShouldUpdateJobAndSetInterruptedTrueAndCancelNonRunnedChildren()
    {
        $rootJob = new Job();
        $rootJob->setId(123);

        $childRunnedJob = new Job();
        $childRunnedJob->setId(1234);
        $childRunnedJob->setStatus(Job::STATUS_RUNNING);
        $childRunnedJob->setRootJob($rootJob);

        $childNewJob = new Job();
        $childNewJob->setId(1235);
        $childNewJob->setStatus(Job::STATUS_NEW);
        $childNewJob->setRootJob($rootJob);

        $childRedeliveredJob = new Job();
        $childRedeliveredJob->setId(1236);
        $childRedeliveredJob->setStatus(Job::STATUS_FAILED_REDELIVERED);
        $childRedeliveredJob->setRootJob($rootJob);

        $rootJob->setChildJobs([$childRunnedJob, $childNewJob, $childRedeliveredJob]);

        $this->jobStorage->expects(self::at(0))
            ->method('saveJob')
            ->willReturnCallback(function (Job $job, $callback) {
                $callback($job);
            });
        $this->jobStorage->expects(self::at(1))
            ->method('saveJob')
            ->with($rootJob);
        $this->jobStorage->expects(self::at(4))
            ->method('saveJob')
            ->with($rootJob);

        $this->jobProcessor->interruptRootJob($rootJob);

        self::assertTrue($rootJob->isInterrupted());
        self::assertNull($rootJob->getStoppedAt());
        self::assertEquals(Job::STATUS_RUNNING, $childRunnedJob->getStatus());
        self::assertEquals(Job::STATUS_CANCELLED, $childNewJob->getStatus());
        self::assertEquals(Job::STATUS_CANCELLED, $childRedeliveredJob->getStatus());
    }

    public function testInterruptRootJobShouldUpdateJobAndSetInterruptedTrueAndStoppedTimeIfForceTrue()
    {
        $rootJob = new Job();
        $rootJob->setId(123);

        $this->jobStorage->expects(self::once())
            ->method('saveJob')
            ->willReturnCallback(function (Job $job, $callback) {
                $callback($job);
            });

        $this->jobProcessor->interruptRootJob($rootJob, true);

        self::assertTrue($rootJob->isInterrupted());
        self::assertLessThanOrEqual(new \DateTime(), $rootJob->getStoppedAt());
    }

    public function testFailAndRedeliveryChildJob()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_RUNNING);

        $this->jobProcessor->failAndRedeliveryChildJob($job);

        self::assertEquals(Job::STATUS_FAILED_REDELIVERED, $job->getStatus());
    }

    public function testFailAndRedeliveryChildJobShouldThrowNotRunningStatus()
    {
        $job = new Job();
        $job->setId(12345);
        $job->setRootJob(new Job());
        $job->setStatus(Job::STATUS_FAILED_REDELIVERED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Can fail and redelivery only running jobs. id: "12345", ' .
            'status: "oro.message_queue_job.status.failed_redelivered"'
        );

        $this->jobProcessor->failAndRedeliveryChildJob($job);
    }

    /**
     * @param Job      $job
     * @param int      $timeForStale
     * @param Job|null $rootJobFoundByStorage
     *
     * @return \PHPUnit\Framework\MockObject\MockObject|JobConfigurationProviderInterface
     */
    private function configureBaseMocksForStaleJobsCases(
        Job $job,
        int $timeForStale = 0,
        $rootJobFoundByStorage = null
    ) {
        $jobConfigurationProvider = $this->createMock(JobConfigurationProviderInterface::class);
        $jobConfigurationProvider->expects(self::any())
            ->method('getTimeBeforeStaleForJobName')
            ->willReturn($timeForStale);

        $this->jobStorage->expects(self::once())
            ->method('createJob')
            ->willReturn($job);
        $this->jobStorage->method('saveJob')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new DuplicateJobException()),
                self::returnCallback(function (Job $job, $callback) {
                    $callback($job);
                })
            );
        $this->jobStorage->expects(self::once())
            ->method('findRootJobByJobNameAndStatuses')
            ->willReturn($rootJobFoundByStorage);

        $this->jobStorage->expects(self::once())
            ->method('findRootJobByOwnerIdAndJobName');

        return $jobConfigurationProvider;
    }

    /**
     * @param Job|null $job
     * @param bool $expectedResult
     * @dataProvider getIsRootJobExistsAndNotStaleProvider
     */
    public function testIsRootJobExistsAndNotStale($job, $expectedResult)
    {
        $this->jobStorage
            ->expects($this->once())
            ->method('findRootJobByJobNameAndStatuses')
            ->with('job-name', [])
            ->willReturn($job);

        static::assertEquals(
            $expectedResult,
            $this->jobProcessor->findNotStaleRootJobyJobNameAndStatuses('job-name', [])
        );
    }

    /**
     * @return array
     */
    public function getIsRootJobExistsAndNotStaleProvider()
    {
        $rootJob = new Job();
        $rootJob->setId(1);
        $rootJob->setChildJobs([]);

        return [
            'job not found' => [
                'job' => null,
                'expectedResult' => null
            ],
            'job not stale' => [
                'job' => $rootJob,
                'expectedResult' => $rootJob
            ]
        ];
    }

    public function testIsRootJobExistsAndNotStaleIfJobStale()
    {
        $rootJob = new Job();
        $rootJob->setId(1);
        $rootJob->setChildJobs([]);

        $this->jobStorage
            ->expects($this->once())
            ->method('findRootJobByJobNameAndStatuses')
            ->willReturn($rootJob);
        $this->jobStorage
            ->expects($this->once())
            ->method('saveJob')
            ->with($rootJob);

        /** @var JobConfigurationProviderInterface|\PHPUnit\Framework\MockObject\MockObject $jobConfigurationProvider */
        $jobConfigurationProvider = $this->createMock(JobConfigurationProviderInterface::class);
        $jobConfigurationProvider
            ->expects($this->any())
            ->method('getTimeBeforeStaleForJobName')
            ->will($this->returnValue(0));

        $this->jobProcessor->setJobConfigurationProvider($jobConfigurationProvider);

        static::assertNull($this->jobProcessor->findNotStaleRootJobyJobNameAndStatuses('job-name', []));
    }
}
