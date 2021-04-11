<?php

declare(strict_types=1);

namespace Rector\Core\Application;

use Rector\ChangesReporting\ValueObjectFactory\FileDiffFactory;
use Rector\Core\Configuration\Configuration;
use Rector\Core\Contract\Processor\FileProcessorInterface;
use Rector\Core\ValueObject\Application\File;
use Symplify\SmartFileSystem\SmartFileSystem;

final class ApplicationFileProcessor
{
    /**
     * @var FileProcessorInterface[]
     */
    private $fileProcessors = [];

    /**
     * @var SmartFileSystem
     */
    private $smartFileSystem;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var FileDiffFactory
     */
    private $fileDiffFactory;

    /**
     * @param FileProcessorInterface[] $fileProcessors
     */
    public function __construct(
        Configuration $configuration,
        SmartFileSystem $smartFileSystem,
        FileDiffFactory $fileDiffFactory,
        array $fileProcessors = []
    ) {
        $this->fileProcessors = $fileProcessors;
        $this->smartFileSystem = $smartFileSystem;
        $this->configuration = $configuration;
        $this->fileDiffFactory = $fileDiffFactory;
    }

    /**
     * @param File[] $files
     */
    public function run(array $files): void
    {
        $this->processFiles($files);

        foreach ($files as $file) {
            if (! $file->hasChanged()) {
                continue;
            }

            // decorate file diffs
            $fileDiff = $this->fileDiffFactory->createFileDiff(
                $file,
                $file->getOriginalFileContent(),
                $file->getFileContent()
            );
            $file->setFileDiff($fileDiff);

            if ($this->configuration->isDryRun()) {
                return;
            }

            $this->printFile($file);
        }
    }

    /**
     * @param File[] $files
     */
    private function processFiles(array $files): void
    {
        foreach ($files as $file) {
            foreach ($this->fileProcessors as $fileProcessor) {
                if (! $fileProcessor->supports($file)) {
                    continue;
                }

                $fileProcessor->process($file);
            }
        }
    }

    private function printFile(File $file): void
    {
        $smartFileInfo = $file->getSmartFileInfo();

        $this->smartFileSystem->dumpFile($smartFileInfo->getPathname(), $file->getFileContent());
        $this->smartFileSystem->chmod($smartFileInfo->getRealPath(), $smartFileInfo->getPerms());
    }
}
