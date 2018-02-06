<?php

namespace GeorgRinger\News\ViewHelpers;

/**
 * This file is part of the "news" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
use GeorgRinger\News\Domain\Model\News;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ViewHelper for a **simple** prev/next link.
 * Only the pid is taken into account, nothing else!
 *
 * # Example: Basic example
 * <code>
 *
 * <n:simplePrevNext pidList="{newsItem.pid}" news="{newsItem}" as="paginated" sortField="datetime">
 *    <f:if condition="{paginated}">
 *        <ul class="prev-next">
 *            <f:if condition="{paginated.prev}">
 *                <li class="previous">
 *                    <n:link newsItem="{paginated.prev}" settings="{settings}">
 *                        {paginated.prev.title}
 *                    </n:link>
 *                </li>
 *            </f:if>
 *            <f:if condition="{paginated.next}">
 *                <li class="next">
 *                    <n:link newsItem="{paginated.next}" settings="{settings}" class="next">
 *                        {paginated.next.title}
 *                    </n:link>
 *                </li>
 *            </f:if>
 *        </ul>
 *    </f:if>
 * </n:simplePrevNext>
 *
 * The attributes includeExternalType & includeInternalType allow to include internal and
 * external news types.
 *
 * </code>
 * <output>
 *  Menu with 2 li items with the link to the previous and next news item.
 * </output>
 *
 */
class SimplePrevNextViewHelper extends \TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper
{

    /* @var $dataMapper \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper */
    protected $dataMapper;

    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Inject the DataMapper
     *
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper $dataMapper
     */
    public function injectDataMapper(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper $dataMapper)
    {
        $this->dataMapper = $dataMapper;
    }

    /**
     * Initialize arguments.
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument('news', News::class, 'news item', true);
        $this->registerArgument('pidList', 'string', 'pid list', false, '');
        $this->registerArgument('sortField', 'string', 'sort field', false, 'datetime');
        $this->registerArgument('as', 'string', 'as', true);
        $this->registerArgument('includeInternalType', 'boolean', 'Include internal news types');
        $this->registerArgument('includeExternalType', 'bool', 'Include external news types');
    }

    /**
     * @return string
     */
    public function render()
    {
        $neighbours = $this->getNeighbours($this->arguments['news'], $this->arguments['pidList'], $this->arguments['sortField']);
        $as = $this->arguments['as'];

        $mapped = $this->mapResultToObjects($neighbours);

        $this->templateVariableContainer->add($as, $mapped);
        $output = $this->renderChildren();
        $this->templateVariableContainer->remove($as);
        return $output;
    }

    /**
     * Map the array from DB to an understandable output
     *
     * @param array $result
     * @return array
     */
    protected function mapResultToObjects(array $result): array
    {
        $out = [];
        foreach ($result as $_id => $single) {
            $out[$_id] = $this->getObject($single['uid']);
        }

        return $out;
    }

    /**
     * Get the news object from the given id
     *
     * @param int $id
     * @return mixed|null
     */
    protected function getObject($id)
    {
        $record = null;

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_news_domain_model_news');
        $rawRecord = $queryBuilder
            ->select('*')
            ->from('tx_news_domain_model_news')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($id, \PDO::PARAM_INT))
            )
            ->setMaxResults(1)
            ->execute()->fetch();

        if (is_object($GLOBALS['TSFE']) && $GLOBALS['TSFE']->sys_language_content > 0) {
            $overlay = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
                'tx_news_domain_model_news',
                $rawRecord,
                $GLOBALS['TSFE']->sys_language_content,
                $GLOBALS['TSFE']->sys_language_contentOL
            );
            if (!is_null($overlay)) {
                $rawRecord = $overlay;
            }
        }

        if (is_array($rawRecord)) {
            $records = $this->dataMapper->map(News::class, [$rawRecord]);
            $record = array_shift($records);
        }

        return $record;
    }

    /**
     * Returns where clause for the news table
     *
     * @return string
     */
    protected function getEnableFieldsWhereClauseForTable()
    {
        $whereClause = '';
        if (is_object($GLOBALS['TSFE']) && is_object($GLOBALS['TSFE']->sys_page)) {
            $whereClause = $GLOBALS['TSFE']->sys_page->enableFields('tx_news_domain_model_news');
        }

        if ((bool)$this->arguments['includeInternalType'] === false) {
            $whereClause .= ' AND tx_news_domain_model_news.type !="1"';
        }
        if ((bool)$this->arguments['includeExternalType'] === false) {
            $whereClause .= ' AND tx_news_domain_model_news.type !="2"';
        }

        return $whereClause;
    }

    /**
     * @param News $news
     * @param $pidList
     * @param $sortField
     * @return array
     */
    protected function getNeighbours(News $news, $pidList, $sortField)
    {
        $data = [];
        $pidList = empty($pidList) ? $news->getPid() : $pidList;

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news');

        foreach (['prev', 'next'] as $label) {
            $queryBuilder = $connection->createQueryBuilder();

            $extraWhere = [];
            if ((bool)$this->arguments['includeInternalType'] === false) {
                $extraWhere[] = $queryBuilder->expr()->neq('type', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT));
            }
            if ((bool)$this->arguments['includeExternalType'] === false) {
                $extraWhere[] = $queryBuilder->expr()->neq('type', $queryBuilder->createNamedParameter(2, \PDO::PARAM_INT));
            }

            $getter = 'get' . ucfirst($sortField) . '';
            if ($news->$getter() instanceof \DateTime) {
                if ($label === 'prev') {
                    $extraWhere[] = $queryBuilder->expr()->lt($sortField, $queryBuilder->createNamedParameter($news->$getter()->getTimestamp(), \PDO::PARAM_INT));
                } else {
                    $extraWhere[] = $queryBuilder->expr()->gt($sortField, $queryBuilder->createNamedParameter($news->$getter()->getTimestamp(), \PDO::PARAM_INT));
                }
            } else {
                if ($label === 'prev') {
                    $extraWhere[] = $queryBuilder->expr()->lt($sortField, $queryBuilder->createNamedParameter($news->$getter(), \PDO::PARAM_STR));
                } else {
                    $extraWhere[] = $queryBuilder->expr()->gt($sortField, $queryBuilder->createNamedParameter($news->$getter(), \PDO::PARAM_STR));
                }
            }

            $row = $queryBuilder
                ->select('uid', 'title', 'pid')
                ->from('tx_news_domain_model_news')
                ->where(
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter(explode(',', $pidList), Connection::PARAM_INT_ARRAY))
                )
                ->andWhere(...$extraWhere)
                ->setMaxResults(1)
                ->orderBy($sortField, ($label === 'prev' ? 'desc' : 'asc'))
                ->execute()->fetch();
            if (is_array($row)) {
                $data[$label] = $row;
            }
        }
        return $data;
    }
}
