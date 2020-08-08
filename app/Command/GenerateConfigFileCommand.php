<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Exception\InvalidArgumentException;
use Throwable;

class GenerateConfigFileCommand extends Command
{
    private const ARGUMENT_TARGET_FILE = 'target_file';
    private const OPTION_CONFIG_FILE = 'file';
    private const OPTION_CONFIG_VARIABLE = 'var';
    private const OPTION_CONFIG_VALUE = 'val';
    private const OPTION_FIRST_FILE_VARIABLES_ONLY = 'first-file-variables-only';

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var array|null
     */
    private $configsData;

    /**
     * @var bool
     */
    private $firstFileVariablesOnly = false;

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('generate-file');

        $this->addArgument(
            self::ARGUMENT_TARGET_FILE,
            InputArgument::REQUIRED,
            'Target file path'
        );

        $this->addOption(
            self::OPTION_CONFIG_FILE,
            null,
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Config file',
            []
        );

        $this->addOption(
            self::OPTION_CONFIG_VARIABLE,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Config variable',
            []
        );

        $this->addOption(
            self::OPTION_CONFIG_VALUE,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Config value',
            []
        );

        $this->addOption(
            self::OPTION_FIRST_FILE_VARIABLES_ONLY,
            null,
            InputOption::VALUE_NONE,
            'Keep only variables from first file'
        );
    }

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->filesystem = new Filesystem();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws Throwable
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->firstFileVariablesOnly = $input->getOption(self::OPTION_FIRST_FILE_VARIABLES_ONLY);

        $files = $input->getOption(self::OPTION_CONFIG_FILE);
        $variables = $input->getOption(self::OPTION_CONFIG_VARIABLE);

        if (0 === count($files) && 0 === count($variables)) {
            throw new InvalidArgumentException('At least one config file or config variable should be specified');
        }

        $values = $input->getOption(self::OPTION_CONFIG_VALUE);

        if (count($variables) !== count($values)) {
            throw new InvalidArgumentException('At least one config file or config variable should be specified');
        }

        $targetFile = $this->getTargetFile();

        if ($this->filesystem->exists($targetFile)) {
            $this->parseFile($targetFile);
        }

        foreach ($files as $file) {
            $this->parseFile($file);
        }

        foreach ($variables as $index => $variable) {
            $this->setVariable($variable, $values[$index]);
        }

        $this->writeTargetFile();

        $this->output->writeln('Config file has been generated');

        return Command::SUCCESS;
    }

    /**
     * @param string $path
     *
     * @return string
     *
     * @throws Throwable
     */
    private function getPath(string $path): string
    {
        if (!$this->filesystem->isAbsolutePath($path)) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    /**
     * @param string $path
     *
     * @throws Throwable
     */
    private function parseFile(string $path): void
    {
        $originalPath = $path;
        $path = $this->getPath($path);

        if (!$this->filesystem->exists($path)) {
            throw new InvalidArgumentException(sprintf('File "%s" does not exist', $originalPath));
        }

        $fileData = explode(PHP_EOL, file_get_contents($path));
        $isFirstFile = false;

        if (null === $this->configsData) {
            $isFirstFile = true;
            $this->configsData = [];
        }

        foreach ($fileData as $row) {
            if (false === strpos($row, '=')) {
                if ($isFirstFile) {
                    $this->configsData[] = $row;
                }

                continue;
            }

            list($variable, $value) = explode('=', $row, 2);

            $this->setVariable($variable, $value, $isFirstFile);
        }
    }

    /**
     * @param string $variable
     * @param string $value
     * @param bool $isFirstFile
     */
    private function setVariable(string $variable, string $value, bool $isFirstFile = false): void
    {
        if (null === $this->configsData) {
            $this->configsData = [$this->makeConfigItem($variable, $value)];

            return;
        }

        $variable = trim($variable);

        foreach (array_keys($this->configsData) as $index) {
            $configData = $this->configsData[$index];

            if (!is_array($configData)) {
                continue;
            }

            if (key($configData) === $variable) {
                $this->configsData[$index][$variable] = $this->trimValue($value);

                return;
            }
        }

        if ($isFirstFile || !$this->firstFileVariablesOnly) {
            $this->configsData[] = $this->makeConfigItem($variable, $value);
        }
    }

    /**
     * @param string $variable
     * @param string $value
     *
     * @return array
     */
    private function makeConfigItem(string $variable, string $value): array
    {
        return [trim($variable) => $this->trimValue($value)];
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function trimValue(string $value): string
    {
        return trim(trim($value), "\"'");
    }

    /**
     * @throws Throwable
     */
    private function writeTargetFile(): void
    {
        foreach (array_keys($this->configsData) as $index) {
            $config = $this->configsData[$index];

            if (is_array($config)) {
                $variable = key($config);
                $value = reset($config);
                $format = '%s=%s';

                if (false !== strpos($variable, 'password')
                    || false !== strpos($value, ' ')
                    || false !== strpos($value, '${')
                ) {
                    $format = '%s="%s"';
                }

                $this->configsData[$index] = sprintf($format, $variable, $value);
            }
        }

        $this->filesystem->dumpFile(
            $this->getTargetFile(),
            implode(PHP_EOL, $this->configsData)
        );
    }

    /**
     * @return string
     *
     * @throws
     */
    private function getTargetFile(): string
    {
        return $this->getPath($this->input->getArgument(self::ARGUMENT_TARGET_FILE));
    }
}
