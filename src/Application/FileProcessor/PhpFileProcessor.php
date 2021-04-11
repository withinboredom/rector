<?php

declare(strict_types=1);

namespace Rector\Core\Application\FileProcessor;

use PhpParser\Lexer;
use Rector\ChangesReporting\Collector\AffectedFilesCollector;
use Rector\Core\Application\TokensByFilePathStorage;
use Rector\Core\Configuration\Configuration;
use Rector\Core\Contract\Processor\FileProcessorInterface;
use Rector\Core\PhpParser\Node\CustomNode\FileNode;
use Rector\Core\PhpParser\NodeTraverser\RectorNodeTraverser;
use Rector\Core\PhpParser\Parser\Parser;
use Rector\Core\PhpParser\Printer\FormatPerservingPrinter;
use Rector\Core\ValueObject\Application\File;
use Rector\Core\ValueObject\Application\ParsedStmtsAndTokens;
use Rector\NodeTypeResolver\FileSystem\CurrentFileInfoProvider;
use Rector\NodeTypeResolver\NodeScopeAndMetadataDecorator;
use Rector\PostRector\Application\PostFileProcessor;
use Symplify\SmartFileSystem\SmartFileInfo;

final class PhpFileProcessor implements FileProcessorInterface
{
    /**
     * @var FormatPerservingPrinter
     */
    private $formatPerservingPrinter;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var Lexer
     */
    private $lexer;

    /**
     * @var RectorNodeTraverser
     */
    private $rectorNodeTraverser;

    /**
     * @var NodeScopeAndMetadataDecorator
     */
    private $nodeScopeAndMetadataDecorator;

    /**
     * @var CurrentFileInfoProvider
     */
    private $currentFileInfoProvider;

    /**
     * @var AffectedFilesCollector
     */
    private $affectedFilesCollector;

    /**
     * @var PostFileProcessor
     */
    private $postFileProcessor;

    /**
     * @var TokensByFilePathStorage
     */
    private $tokensByFilePathStorage;

    /**
     * @var Configuration
     */
    private $configuration;

    public function __construct(
        Configuration $configuration,
        AffectedFilesCollector $affectedFilesCollector,
        CurrentFileInfoProvider $currentFileInfoProvider,
        FormatPerservingPrinter $formatPerservingPrinter,
        Lexer $lexer,
        NodeScopeAndMetadataDecorator $nodeScopeAndMetadataDecorator,
        Parser $parser,
        PostFileProcessor $postFileProcessor,
        RectorNodeTraverser $rectorNodeTraverser,
        TokensByFilePathStorage $tokensByFilePathStorage
    ) {
        $this->configuration = $configuration;
        $this->formatPerservingPrinter = $formatPerservingPrinter;
        $this->parser = $parser;
        $this->lexer = $lexer;
        $this->rectorNodeTraverser = $rectorNodeTraverser;
        $this->nodeScopeAndMetadataDecorator = $nodeScopeAndMetadataDecorator;
        $this->currentFileInfoProvider = $currentFileInfoProvider;
        $this->affectedFilesCollector = $affectedFilesCollector;
        $this->postFileProcessor = $postFileProcessor;
        $this->tokensByFilePathStorage = $tokensByFilePathStorage;
    }

    public function process(File $file): void
    {
        $this->refactor($file);
        $this->postFileRefactor($file);

        $smartFileInfo = $file->getSmartFileInfo();
        $parsedStmtsAndTokens = $this->tokensByFilePathStorage->getForFileInfo($smartFileInfo);

        // @todo not printable yet..., wait for all the traverse
        $changedContent = $this->formatPerservingPrinter->printParsedStmstAndTokens(
            $smartFileInfo,
            $parsedStmtsAndTokens
        );
        $file->changeFileContent($changedContent);

        // change content
    }

    public function supports(File $file): bool
    {
        $fileInfo = $file->getSmartFileInfo();
        return $fileInfo->hasSuffixes($this->getSupportedFileExtensions());
    }

    /**
     * @return string[]
     */
    public function getSupportedFileExtensions(): array
    {
        return $this->configuration->getFileExtensions();
    }

    public function refactor(File $file): void
    {
        $this->parseFileInfoToLocalCache($file->getSmartFileInfo());
        $parsedStmtsAndTokens = $this->tokensByFilePathStorage->getForFileInfo($file->getSmartFileInfo());

        $this->currentFileInfoProvider->setCurrentStmts($parsedStmtsAndTokens->getNewStmts());

        // run file node only if
        $fileNode = new FileNode($file->getSmartFileInfo(), $parsedStmtsAndTokens->getNewStmts());
        $this->rectorNodeTraverser->traverseFileNode($fileNode);

        $newStmts = $this->rectorNodeTraverser->traverse($parsedStmtsAndTokens->getNewStmts());

        // this is needed for new tokens added in "afterTraverse()"
        $parsedStmtsAndTokens->updateNewStmts($newStmts);

        $this->affectedFilesCollector->removeFromList($file);
        while ($otherTouchedFile = $this->affectedFilesCollector->getNext()) {
            $this->refactor($otherTouchedFile);
        }
    }

    private function parseFileInfoToLocalCache(SmartFileInfo $fileInfo): void
    {
        if ($this->tokensByFilePathStorage->hasForFileInfo($fileInfo)) {
            return;
        }

        $this->currentFileInfoProvider->setCurrentFileInfo($fileInfo);

        // store tokens by absolute path, so we don't have to print them right now
        $parsedStmtsAndTokens = $this->parseAndTraverseFileInfoToNodes($fileInfo);
        $this->tokensByFilePathStorage->addForRealPath($fileInfo, $parsedStmtsAndTokens);
    }

    private function postFileRefactor(File $file): void
    {
        $smartFileInfo = $file->getSmartFileInfo();

        if (! $this->tokensByFilePathStorage->hasForFileInfo($smartFileInfo)) {
            $this->parseFileInfoToLocalCache($smartFileInfo);
        }

        $parsedStmtsAndTokens = $this->tokensByFilePathStorage->getForFileInfo($smartFileInfo);

        $this->currentFileInfoProvider->setCurrentStmts($parsedStmtsAndTokens->getNewStmts());
        $this->currentFileInfoProvider->setCurrentFileInfo($smartFileInfo);

        $newStmts = $this->postFileProcessor->traverse($parsedStmtsAndTokens->getNewStmts());

        // this is needed for new tokens added in "afterTraverse()"
        $parsedStmtsAndTokens->updateNewStmts($newStmts);
    }

    private function parseAndTraverseFileInfoToNodes(SmartFileInfo $smartFileInfo): \ParsedStmtsAndTokens
    {
        $oldStmts = $this->parser->parseFileInfo($smartFileInfo);
        $oldTokens = $this->lexer->getTokens();

        // needed for \Rector\NodeTypeResolver\PHPStan\Scope\NodeScopeResolver
        $parsedStmtsAndTokens = new ParsedStmtsAndTokens($oldStmts, $oldStmts, $oldTokens);
        $this->tokensByFilePathStorage->addForRealPath($smartFileInfo, $parsedStmtsAndTokens);

        $newStmts = $this->nodeScopeAndMetadataDecorator->decorateNodesFromFile($oldStmts, $smartFileInfo);

        return new ParsedStmtsAndTokens($newStmts, $oldStmts, $oldTokens);
    }
}
