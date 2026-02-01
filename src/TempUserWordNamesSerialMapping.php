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
use WANObjectCache;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

class TempUserWordNamesSerialMapping implements SerialMapping {
    private LoggerInterface $logger;
    private Config $config;
    private WANObjectCache $objectCache;
    private RevisionStoreFactory $revisionStoreFactory;
	private PageStoreFactory $pageStoreFactory;

    /** @var string[] */
    private array $defaultWords = [
        'Apple', 'Banana', 'Cherry', 'Grape', 'Peach', 'Pear', 'Strawberry', 'Watermelon', 'Apricot', 'Blueberry',
        'Orange', 'Tomato', 'Plum', 'Lime', 'Lemon', 'Bread', 'Egg', 'Fish', 'Garlic', 'Sugar', 'Bagel', 'Tofu',
        'Muffin', 'Cake', 'Perfect', 'Cheerful', 'Generous', 'Friendly', 'Happy', 'Important', 'Great', 'Real',
        'Strong', 'Delighted', 'Merry', 'Sunny', 'Jovial', 'Elated', 'Lucky', 'Golden', 'Blissful', 'Pretty',
        'Silly', 'Red', 'Yellow', 'Green', 'Blue', 'Orange', 'Purple', 'Pink', 'Cyan', 'Magenta', 'Fluorescent'
    ];

    private int $offset;
    private int $numWords;
    private array $words = [];
    private bool $useIndex;

    public function __construct(
        array $config,
        Config $mainConfig,
        WANObjectCache $objectCache,
        RevisionStoreFactory $revisionStoreFactory,
		PageStoreFactory $pageStoreFactory
    ) {
        $this->logger = LoggerFactory::getInstance( 'TempUserWordNames' );
        $this->config = $mainConfig;
        $this->objectCache = $objectCache;
        $this->revisionStoreFactory = $revisionStoreFactory;
		$this->pageStoreFactory = $pageStoreFactory;

        $this->offset = $config['offset'] ?? 0;
        $this->numWords = $mainConfig->get( 'TempUserWordNamesLength' );
        $this->words = $this->getWordList();
        $this->useIndex = $mainConfig->get( 'TempUserWordNamesUseIndex' );
    }

    public function getWordList(): array {
        if ( !empty( $this->words ) ) {
            return $this->words;
        }

        $listConfig = $this->config->get( 'TempUserWordNamesList' );
        if ( !$listConfig ) {
            throw new InvalidArgumentException( '$wgTempUserNamesList must be defined!' );
        }

        $words = [];

        if ( isset( $listConfig[ 'words' ] ) ) {
            $words = $listConfig[ 'words' ];
        } else if ( isset( $listConfig[ 'page' ] ) ) {
            $pageName = $listConfig[ 'page' ];
            $targetWiki = $this->config->get( 'TempUserWordNamesCentralWiki' )
				?? $this->config->get( MainConfigNames::DBname );

            $words = $this->objectCache->getWithSetCallback(
                $this->objectCache->makeGlobalKey( 'tempuserwordnames', 'words' ),
                ExpirationAwareness::TTL_HOUR,
                function () use ( $pageName, $targetWiki ) {
					// Note: we have no idea what the remote namespaces are at this point, so hopefully they match ours
					$targetWikiIsCurrentWiki = $targetWiki === $this->config->get( MainConfigNames::DBname );
					$page = $this->pageStoreFactory
						->getPageStore( $targetWikiIsCurrentWiki ? WikiAwareEntity::LOCAL : $targetWiki )
						->getPageByText( $pageName );
                    $rev = $this->revisionStoreFactory
						->getRevisionStore($targetWikiIsCurrentWiki ? WikiAwareEntity::LOCAL : $targetWiki )
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
            );

            if ( empty( $words ) ) {
                $this->logger->warning( "Configured word list is empty. Using fallback list." );
                $words = $this->defaultWords;
            }
        }

        if ( $this->numWords <= 0 || $this->numWords > count( $words ) ) {
            $this->logger->warning( '$wgTempUserWordNamesLength is less than 1 or more than the length of the list.' .
                ' Using fallback list.' );
            $words = $this->defaultWords;
        }

        $this->words = $words;
        return $words;
    }

    public function getSerialIdForIndex( int $index ): string {
        $selected = array_map( function ( $k ) {
            return ucfirst( $this->words[ $k ] );
        }, array_rand( $this->words, $this->numWords ) );
        shuffle( $selected );
        return implode( '', $selected ) . ( $this->useIndex ? $index + $this->offset : '' );
    }
}
