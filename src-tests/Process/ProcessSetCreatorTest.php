<?php

namespace Lmc\Steward\Process;

use Lmc\Steward\Console\Command\RunCommand;
use Lmc\Steward\Console\CommandEvents;
use Lmc\Steward\Console\Event\RunTestsProcessEvent;
use Lmc\Steward\Process\Fixtures\DelayedTests\DelayedByZeroTimeTest;
use Lmc\Steward\Process\Fixtures\DelayedTests\DelayedTest;
use Lmc\Steward\Process\Fixtures\DelayedTests\FirstTest;
use Lmc\Steward\Publisher\AbstractPublisher;
use Lmc\Steward\Publisher\XmlPublisher;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;

/**
 * @covers Lmc\Steward\Process\ProcessSetCreator
 */
class ProcessSetCreatorTest extends \PHPUnit_Framework_TestCase
{
    /** @var EventDispatcher|\PHPUnit_Framework_MockObject_MockObject */
    protected $dispatcherMock;
    /** @var RunCommand|\PHPUnit_Framework_MockObject_MockObject */
    protected $command;
    /** @var InputInterface|\PHPUnit_Framework_MockObject_MockObject */
    protected $input;
    /** @var BufferedOutput */
    protected $bufferedOutput;
    /** @var ProcessSetCreator */
    protected $creator;
    /** @var AbstractPublisher|\PHPUnit_Framework_MockObject_MockObject */
    protected $publisherMock;

    // Fully classified names of dummy tests
    const NAME_DUMMY_TEST = 'Lmc\Steward\Process\Fixtures\DummyTests\DummyTest';
    const NAME_BAR_TEST = 'Lmc\Steward\Process\Fixtures\DummyTests\GroupBarTest';
    const NAME_FOO_TEST = 'Lmc\Steward\Process\Fixtures\DummyTests\GroupFooTest';

    public function setUp()
    {
        $this->dispatcherMock = $this->getMockBuilder(EventDispatcher::class)
            ->setMethods(['dispatch'])
            ->getMock();

        $this->command = new RunCommand($this->dispatcherMock);

        $this->input = new StringInput('staging firefox');
        $this->input->bind($this->command->getDefinition());

        $this->bufferedOutput = new BufferedOutput();
        $this->bufferedOutput->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $this->bufferedOutput->setDecorated(false);

        $this->publisherMock = $this->getMockBuilder(XmlPublisher::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->creator = new ProcessSetCreator(
            $this->command,
            $this->input,
            $this->bufferedOutput,
            $this->publisherMock
        );
    }

    public function testShouldCreateEmptyProcessSetIfNoFilesGiven()
    {
        $finderMock = $this->getMockBuilder(Finder::class)
            ->setMethods(['getIterator'])
            ->getMock();

        $finderMock->expects($this->once())
            ->method('getIterator')
            ->willReturn(new \AppendIterator());

        $processSet = $this->creator->createFromFiles($finderMock, [], []);

        $this->assertInstanceOf(ProcessSet::class, $processSet);
        $this->assertCount(0, $processSet);
    }

    public function testShouldCreateProcessSetFromGivenFiles()
    {
        $processSet = $this->creator->createFromFiles($this->findDummyTests(), [], [], '');

        $this->assertQueuedTests([self::NAME_DUMMY_TEST, self::NAME_BAR_TEST, self::NAME_FOO_TEST], $processSet);

        // Test properties of DummyTest
        $processes = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED);
        $dummyTestProcess = $processes[self::NAME_DUMMY_TEST]->getProcess();
        $testCommand = $dummyTestProcess->getCommandLine();
        $testEnv = $dummyTestProcess->getEnv();

        $this->assertContains('phpunit', $testCommand);
        $this->assertContains('--log-junit=logs/Lmc-Steward-Process-Fixtures-DummyTests-DummyTest.xml', $testCommand);
        $this->assertNotContains('--colors', $testCommand); // Decorated output is disabled in setUp()
        $this->assertNotContains('--filter', $testCommand);
        $this->assertRegExp('/--configuration=.*\/src\/phpunit\.xml/', $testCommand);

        // Check defaults were passed to the Processes
        $definition = $this->command->getDefinition();
        $expectedEnv = [
            'BROWSER_NAME' => 'firefox',
            'ENV' => 'staging',
            'SERVER_URL' => $definition->getOption(RunCommand::OPTION_SERVER_URL)->getDefault(),
            'FIXTURES_DIR' => $definition->getOption(RunCommand::OPTION_FIXTURES_DIR)->getDefault(),
            'LOGS_DIR' => $definition->getOption(RunCommand::OPTION_LOGS_DIR)->getDefault(),
        ];
        $this->assertArraySubset($expectedEnv, $testEnv);
    }

    public function testShouldThrowExceptionIfAddingFileWithNoClass()
    {
        $files = $this->findDummyTests('NoClassTest.php', 'InvalidTests');
        $fileName = key(iterator_to_array($files->getIterator()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No class found in file "' . $fileName . '"');

        $this->creator->createFromFiles($files, [], []);
    }

    public function testShouldThrowExceptionIfAddingClassWithNameMismatchingTheFileName()
    {
        $files = $this->findDummyTests('WrongClassTest.php', 'InvalidTests');

        $fileName = key(iterator_to_array($files->getIterator()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Error loading class "Lmc\Steward\Process\Fixtures\InvalidTests\ReallyWrongClassTest" from file "'
            . $fileName . '". Make sure the class name and namespace matches the file path.'
        );

        $this->creator->createFromFiles($files, [], []);
    }

    public function testShouldThrowExceptionIfMultipleClassesAreDefinedInFile()
    {
        $files = $this->findDummyTests('MultipleClassesInFileTest.php', 'InvalidTests');
        $fileName = key(iterator_to_array($files->getIterator()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'File "' . $fileName . '" contains definition of 2 classes. However, each class must be defined in its'
            . ' own separate file.'
        );
        $this->creator->createFromFiles($files, [], []);
    }

    public function testShouldOnlyAddTestsOfGivenGroups()
    {
        $processSet = $this->creator->createFromFiles($this->findDummyTests(), ['bar', 'foo'], []);

        $this->assertQueuedTests([self::NAME_BAR_TEST, self::NAME_FOO_TEST], $processSet);

        $output = $this->bufferedOutput->fetch();
        $this->assertContains('by group(s): bar, foo', $output);
        $this->assertRegExp('/Found testcase file #1 in group bar: .*\/GroupBarTest\.php/', $output);
        $this->assertRegExp('/Found testcase file #2 in group foo: .*\/GroupFooTest\.php/', $output);
    }

    public function testShouldExcludeTestsOfGivenGroups()
    {
        $processSet = $this->creator->createFromFiles($this->findDummyTests(), [], ['bar', 'foo']);

        $this->assertQueuedTests([self::NAME_DUMMY_TEST], $processSet);

        $output = $this->bufferedOutput->fetch();
        $this->assertContains('excluding group(s): bar, foo', $output);
        $this->assertRegExp('/Excluding testcase file .*\/GroupBarTest\.php with group bar/', $output);
        $this->assertRegExp('/Excluding testcase file .*\/GroupFooTest\.php with group foo/', $output);
    }

    public function testShouldAddTestsOfGivenGroupsButExcludeFromThemThoseOfExcludedGroups()
    {
        // group "both" gets included (incl. GroupFooTest and GroupBarTest), but "GroupBarTest" gets excluded
        $processSet = $this->creator->createFromFiles($this->findDummyTests(), ['both'], ['bar']);

        $this->assertQueuedTests([self::NAME_FOO_TEST], $processSet);

        $output = $this->bufferedOutput->fetch();
        $this->assertContains('by group(s): both', $output);
        $this->assertContains('excluding group(s): bar', $output);
        $this->assertRegExp('/Found testcase file #1 in group both: .*\/GroupFooTest\.php/', $output);
        $this->assertRegExp('/Excluding testcase file .*\/GroupBarTest\.php with group bar/', $output);
    }

    public function testShouldAddTestsWithTheirDefinedDelay()
    {
        $files = $this->findDummyTests('*Test.php', 'DelayedTests');
        $processSet = $this->creator->createFromFiles($files, [], []);

        $processes = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED);

        $firstTest = $processes[FirstTest::class];
        $delayedTest = $processes[DelayedTest::class];
        $delayedByZeroTimeTest = $processes[DelayedByZeroTimeTest::class];

        $this->assertFalse($firstTest->isDelayed());

        $this->assertTrue($delayedByZeroTimeTest->isDelayed());
        $this->assertSame(FirstTest::class, $delayedByZeroTimeTest->getDelayAfter());
        $this->assertSame(0.0, $delayedByZeroTimeTest->getDelayMinutes());

        $this->assertTrue($delayedTest->isDelayed());
        $this->assertSame(FirstTest::class, $delayedTest->getDelayAfter());
        $this->assertSame(3.33, $delayedTest->getDelayMinutes());
    }

    public function testShouldIgnoreTestsDelays()
    {
        $files = $this->findDummyTests('*Test.php', 'DelayedTests');
        $processSet = $this->creator->createFromFiles($files, [], [], null, true);

        $processes = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED);

        $firstTest = $processes[FirstTest::class];
        $delayedTest = $processes[DelayedTest::class];
        $delayedByZeroTimeTest = $processes[DelayedByZeroTimeTest::class];

        $this->assertFalse($firstTest->isDelayed());
        $this->assertFalse($delayedByZeroTimeTest->isDelayed());
        $this->assertFalse($delayedTest->isDelayed());
    }

    public function testShouldThrowExceptionIfAddingTestWithDelayTimeButWithoutDelayedClass()
    {
        $files = $this->findDummyTests('InvalidDelayTest.php', 'InvalidTests');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Testcase "Lmc\Steward\Process\Fixtures\InvalidTests\InvalidDelayTest" has defined delay 5 minutes, '
            . 'but doesn\'t have defined the testcase to run after'
        );

        $this->creator->createFromFiles($files, [], []);
    }

    public function testShouldPassFilterOptionToPhpunitProcess()
    {
        $processSet = $this->creator->createFromFiles($this->findDummyTests(), [], [], 'testCase::testName');

        $processes = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED);
        $dummyTestProcess = $processes[self::NAME_DUMMY_TEST]->getProcess();
        $testCommand = $dummyTestProcess->getCommandLine();
        $output = $this->bufferedOutput->fetch();

        $this->assertContains('Filtering testcases:', $output);
        $this->assertContains('by testcase/test name: testCase::testName', $output);
        $this->assertContains('--filter=testCase::testName', $testCommand);
    }

    public function testShouldPropagateCustomOptionsIntoProcess()
    {
        $this->input = new StringInput(
            'trolling chrome'
            . ' --' . RunCommand::OPTION_SERVER_URL . '=http://foo.bar:1337'
            . ' --' . RunCommand::OPTION_FIXTURES_DIR . '=custom-fixtures-dir/'
            . ' --' . RunCommand::OPTION_LOGS_DIR . '=custom-logs-dir/'
            . ' --' . RunCommand::OPTION_CAPABILITY . '=webdriver.log.file:/foo/bar.log'
            . ' --' . RunCommand::OPTION_CAPABILITY . '="capability.in.quotes:/foo/ba r.log"'
            . ' --' . RunCommand::OPTION_CAPABILITY . '="platform:OS X 10.8"'
        );

        $this->input->bind($this->command->getDefinition());

        // Redeclare creator so it uses the new input
        $this->creator = new ProcessSetCreator(
            $this->command,
            $this->input,
            $this->bufferedOutput,
            $this->publisherMock
        );

        $files = $this->findDummyTests('DummyTest.php');

        $processSet = $this->creator->createFromFiles($files, [], []);

        $process = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED)[self::NAME_DUMMY_TEST]->getProcess();
        $processEnv = $process->getEnv();

        $this->assertArraySubset(
            [
                'BROWSER_NAME' => 'chrome',
                'ENV' => 'trolling',
                'SERVER_URL' => 'http://foo.bar:1337',
                'FIXTURES_DIR' => 'custom-fixtures-dir/',
                'LOGS_DIR' => 'custom-logs-dir/',
                'CAPABILITY' => '{"webdriver.log.file":"\/foo\/bar.log","capability.in.quotes":"\/foo\/ba r.log",'
                    . '"platform":"OS X 10.8"}',
            ],
            $processEnv
        );
    }

    public function testShouldNotAcceptCapabilitiesInWrongFormat()
    {
        $this->input = new StringInput(
            'foo chrome'
            . ' --' . RunCommand::OPTION_CAPABILITY . '=foo'
        );

        $this->input->bind($this->command->getDefinition());

        // Redeclare creator so it uses the new input
        $this->creator = new ProcessSetCreator(
            $this->command,
            $this->input,
            $this->bufferedOutput,
            $this->publisherMock
        );

        $files = $this->findDummyTests('DummyTest.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Capability must be given in format "capabilityName:value" but "foo" was given');

        $this->creator->createFromFiles($files, [], []);
    }

    public function testShouldSetPHPUnitColoredOptionOnlyIfTheOutputIsDecorated()
    {
        $files = $this->findDummyTests('DummyTest.php');

        // Test default commands (decorated output was disabled in setUp)
        $processSet = $this->creator->createFromFiles($files, [], []);
        $process = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED)[self::NAME_DUMMY_TEST]->getProcess();
        $commandWithoutColors = $process->getCommandLine();
        $this->assertNotContains('--colors', $commandWithoutColors);

        // Enable decorated output and test if the option is added to the command
        $this->bufferedOutput->setDecorated(true);
        $this->creator = new ProcessSetCreator(
            $this->command,
            $this->input,
            $this->bufferedOutput,
            $this->publisherMock
        );

        $processSet = $this->creator->createFromFiles($files, [], []);
        $process = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED)[self::NAME_DUMMY_TEST]->getProcess();
        $commandWithColors = $process->getCommandLine();
        $this->assertContains('--colors=always', $commandWithColors);
    }

    public function testShouldDispatchProcessEvent()
    {
        $this->dispatcherMock->expects($this->at(0))
            ->method('dispatch')
            ->with($this->equalTo(CommandEvents::RUN_TESTS_PROCESS), $this->isInstanceOf(RunTestsProcessEvent::class));

        $this->creator->createFromFiles($this->findDummyTests(), [], ['bar', 'foo']);
    }

    /**
     * @param string $pattern
     * @param string $directory
     * @return Finder
     */
    protected function findDummyTests($pattern = '*Test.php', $directory = 'DummyTests')
    {
        return (new Finder())
            ->files()
            ->in(__DIR__ . '/Fixtures/' . $directory)
            ->name($pattern);
    }

    /**
     * Assert ProcessSet consists only of tests of expected names
     * @param string[] $expectedTestNames
     * @param ProcessSet $processSet
     */
    protected function assertQueuedTests(array $expectedTestNames, $processSet)
    {
        $this->assertInstanceOf(ProcessSet::class, $processSet);
        $this->assertCount(count($expectedTestNames), $processSet);
        $processes = $processSet->get(ProcessWrapper::PROCESS_STATUS_QUEUED);

        foreach ($expectedTestNames as $expectedTestName) {
            $this->assertArrayHasKey($expectedTestName, $processes);
            $this->assertInstanceOf(ProcessWrapper::class, $processes[$expectedTestName]);
        }
    }
}
