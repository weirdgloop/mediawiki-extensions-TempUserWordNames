<?php

namespace MediaWiki\Extension\TempUserWordNames;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Content\TextContent;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\TempUser\SerialMapping;
use Psr\Log\LoggerInterface;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;

class TempUserWordNamesSerialMapping implements SerialMapping {
    private readonly LoggerInterface $logger;

    private const DEFAULT_WORDS = [
        'Apple', 'Banana', 'Cherry', 'Grape', 'Peach', 'Pear', 'Strawberry', 'Watermelon', 'Apricot', 'Blueberry',
        'Orange', 'Tomato', 'Plum', 'Lime', 'Lemon', 'Bread', 'Egg', 'Fish', 'Garlic', 'Sugar', 'Bagel', 'Tofu',
        'Muffin', 'Cake', 'Perfect', 'Cheerful', 'Generous', 'Friendly', 'Happy', 'Important', 'Great', 'Real',
        'Strong', 'Delighted', 'Merry', 'Sunny', 'Jovial', 'Elated', 'Lucky', 'Golden', 'Blissful', 'Pretty',
        'Silly', 'Red', 'Yellow', 'Green', 'Blue', 'Orange', 'Purple', 'Pink', 'Cyan', 'Magenta', 'Fluorescent'
    ];

    private readonly int $offset;
    private readonly int $numWords;
    private readonly array $words;
    private readonly bool $useIndex;

    public function __construct(
        array $serialMappingConfig,
        private readonly Config $config,
        private readonly WANObjectCache $objectCache,
        private readonly RevisionStoreFactory $revisionStoreFactory,
		private readonly PageStoreFactory $pageStoreFactory
    ) {
        $this->logger = LoggerFactory::getInstance( 'TempUserWordNames' );

        $this->offset = $serialMappingConfig['offset'] ?? 0;
        $this->numWords = $this->config->get( 'TempUserWordNamesLength' );
        $this->words = $this->loadWordList();
        $this->useIndex = $this->config->get( 'TempUserWordNamesUseIndex' );
    }

    public function getWordList(): array {
        return $this->words;
    }

    private function loadWordList(): array {
        $listConfig = $this->config->get( 'TempUserWordNamesList' );
        if ( !$listConfig ) {
            throw new InvalidArgumentException( '$wgTempUserNamesList must be defined!' );
        }

        $words = [];
        if ( isset( $listConfig[ 'words' ] ) ) {
            $words = $listConfig[ 'words' ];
        } else if ( isset( $listConfig[ 'page' ] ) ) {
            $words = $this->fetchWordListFromPage( $listConfig[ 'page' ] );
            if ( empty( $words ) ) {
                $this->logger->warning( "Configured word list is empty. Using fallback list." );
                $words = self::DEFAULT_WORDS;
            }
        }

        if ( $this->numWords <= 0 || $this->numWords > count( $words ) ) {
            $this->logger->warning( '$wgTempUserWordNamesLength is less than 1 or more than the length of the list.' .
                ' Using fallback list.' );
            $words = self::DEFAULT_WORDS;
        }

        return $words;
    }

    /**
     * Fetch the word list from the configured wiki page, caching the result.
     *
     * @param string $pageName
     * @return string[]|false List of words, or false when the page has no usable content.
     */
    private function fetchWordListFromPage( string $pageName ): array|false {
        $targetWiki = $this->config->get( 'TempUserWordNamesCentralWiki' )
            ?? $this->config->get( MainConfigNames::DBname );

        return $this->objectCache->getWithSetCallback(
            $this->objectCache->makeGlobalKey( 'tempuserwordnames', $targetWiki, 'words' ),
            ExpirationAwareness::TTL_HOUR,
            fn () => $this->readWordListFromPage( $pageName, $targetWiki )
        );
    }

    /**
     * Read the word list from a wiki page on the given wiki.
     *
     * @param string $pageName
     * @param string $targetWiki
     * @return string[]|false List of words, or false when the page has no usable content.
     */
    private function readWordListFromPage( string $pageName, string $targetWiki ): array|false {
        // Note: we have no idea what the remote namespaces are at this point, so hopefully they match ours
        $targetWikiIsCurrentWiki = $targetWiki === $this->config->get( MainConfigNames::DBname );
        $wikiId = $targetWikiIsCurrentWiki ? WikiAwareEntity::LOCAL : $targetWiki;

        $page = $this->pageStoreFactory
            ->getPageStore( $wikiId )
            ->getPageByText( $pageName );
        $rev = $this->revisionStoreFactory
            ->getRevisionStore( $wikiId )
            ->getRevisionByTitle( $page );
        $content = $rev?->getContent( SlotRecord::MAIN );
        if ( !$content ) {
            $this->logger->warning( "No main slot on configured wiki page: $pageName" );
            return false;
        }

        $text = ( $content instanceof TextContent ) ? $content->getText() : '';
        if ( !$text ) {
            $this->logger->warning( "Empty content on configured wiki page: $pageName" );
            return false;
        }

        return array_map( 'trim', explode( "\n", $text ) );
    }

    public function getSerialIdForIndex( int $index ): string {
        $selected = array_map( function ( $k ) {
            return ucfirst( $this->words[ $k ] );
        }, array_rand( $this->words, $this->numWords ) );
        shuffle( $selected );
        return implode( '', $selected ) . ( $this->useIndex ? $index + $this->offset : '' );
    }
}
