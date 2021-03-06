<?php

namespace Lmc\Steward\Console\Style;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class StewardStyleTest extends \PHPUnit_Framework_TestCase
{
    /** @var StewardStyle */
    protected $style;
    /** @var BufferedOutput */
    protected $outputBuffer;

    public function setUp()
    {
        $input = new StringInput('');
        $this->outputBuffer = new BufferedOutput();

        $this->style = new StewardStyle($input, $this->outputBuffer);
        $this->forceLineLength($this->style);
    }

    /**
     * @dataProvider runStatusProvider
     * @param string $method
     */
    public function testShouldFormatRunStatusWithTimestamp($method)
    {
        call_user_func([$this->style, $method], 'Foo bar');

        $output = $this->outputBuffer->fetch();
        $this->assertRegExp('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $output);
        $this->assertContains('Foo bar', $output);
    }

    /**
     * @return array[]
     */
    public function runStatusProvider()
    {
        return [
            ['runStatus'],
            ['runStatusSuccess'],
            ['runStatusError'],
        ];
    }

    public function testShouldFormatSection()
    {
        $this->style->section('Section header');

        $this->assertStringEqualsFile(__DIR__ . '/Fixtures/section.txt', $this->outputBuffer->fetch());
    }

    public function testShouldFormatOutputWithExtraColors()
    {
        $input = new StringInput('');
        $outputMock = $this->getMockBuilder(BufferedOutput::class)
            ->setMethods(['write', 'writeln'])
            ->getMock();

        $outputMock->expects($this->at(0))
            ->method('write')
            ->with('Foo\Bar> ');

        $outputMock->expects($this->at(1))
            ->method('writeln')
            ->with('Basic output');

        $outputMock->expects($this->at(2))
            ->method('write')
            ->with('Foo\Bar> ');

        $outputMock->expects($this->at(3))
            ->method('writeln')
            ->with('<fg=black;bg=yellow>[WARN] Warning output</fg=black;bg=yellow>');

        $outputMock->expects($this->at(4))
            ->method('write')
            ->with('Foo\Bar> ');

        $outputMock->expects($this->at(5))
            ->method('writeln')
            ->with('<comment>[DEBUG] Debug output</comment>');

        $style = new StewardStyle($input, $outputMock);
        $this->forceLineLength($style);

        $rawOutput = <<<HTXT
Basic output
[WARN] Warning output
[DEBUG] Debug output
HTXT;

        $style->output($rawOutput, 'Foo\Bar');
    }

    public function testShouldFormatErrorOutput()
    {
        $input = new StringInput('');
        $outputMock = $this->getMockBuilder(BufferedOutput::class)
            ->setMethods(['write', 'writeln'])
            ->getMock();

        $outputMock->expects($this->exactly(1))
            ->method('write')
            ->with('<error>Foo\Bar ERR> ');

        $outputMock->expects($this->exactly(1))
            ->method('writeln')
            ->with('Error output with trailing whitespace</>');

        $style = new StewardStyle($input, $outputMock);
        $this->forceLineLength($style);

        $rawOutput = <<<HTXT
Error output with trailing whitespace


HTXT;

        $style->errorOutput($rawOutput, 'Foo\Bar');
    }

    public function testShouldNotProduceOutputForEmptyOutput()
    {
        $this->style->output('', 'Foo');
        $this->assertEmpty($this->outputBuffer->fetch());
    }

    public function testShouldNotProduceOutputForEmptyErrorOutput()
    {
        $this->style->errorOutput('', 'Foo');
        $this->assertEmpty($this->outputBuffer->fetch());
    }

    public function testShouldFormatText()
    {
        $this->style->text('Text message');

        $this->assertSame("Text message\n", $this->outputBuffer->fetch());
    }

    public function testShouldFormatSuccess()
    {
        $this->style->success('Success message');

        $this->assertStringEqualsFile(__DIR__ . '/Fixtures/success.txt', $this->outputBuffer->fetch());
    }

    public function testShouldFormatError()
    {
        $this->style->error('Error message');

        $this->assertStringEqualsFile(__DIR__ . '/Fixtures/error.txt', $this->outputBuffer->fetch());
    }

    /**
     * @dataProvider notImplementedProvider
     * @param string $method
     * @param array $args
     */
    public function testShouldThrowExceptionOnNotImplementedMethods($method, array $args)
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Method not implemented');

        call_user_func_array([$this->style, $method], $args);
    }

    /**
     * @return array[]
     */
    public function notImplementedProvider()
    {
        return [
            ['title', ['foo']],
            ['listing', [['foo', 'bar']]],
            ['warning', ['foo']],
            ['note', ['foo']],
            ['caution', ['foo']],
            ['table', [[], []]],
            ['ask', ['foo']],
            ['askHidden', ['foo']],
            ['confirm', ['foo']],
            ['choice', ['foo', []]],
            ['progressStart', []],
            ['progressAdvance', []],
            ['progressAdvance', []],
            ['progressFinish', []],
        ];
    }

    /**
     * Force the line length to ensure a consistent output for expectations
     * @param StewardStyle $style
     */
    private function forceLineLength(StewardStyle $style)
    {
        $symfonyStyleProperty = new \ReflectionProperty(get_class($style), 'symfonyStyle');
        $symfonyStyleProperty->setAccessible(true);
        $symfonyStyle = $symfonyStyleProperty->getValue($style);

        $lineLengthProperty = new \ReflectionProperty(get_class($symfonyStyle), 'lineLength');
        $lineLengthProperty->setAccessible(true);
        $lineLengthProperty->setValue($symfonyStyle, 120);
    }
}
