<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Solr engine.
 *
 * @package    search_solr
 * @copyright  2015 Daniel Neis Araujo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_solr;

defined('MOODLE_INTERNAL') || die();

/**
 * Solr engine.
 *
 * @package    search_solr
 * @copyright  2015 Daniel Neis Araujo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine extends \core_search\engine {

    /**
     * @var string The date format used by solr.
     */
    const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @var int Commit documents interval (number of miliseconds).
     */
    const AUTOCOMMIT_WITHIN = 15000;

    /**
     * The maximum number of results to fetch at a time.
     */
    const QUERY_SIZE = 120;

    /**
     * Highlighting fragsize. Slightly larger than output size (500) to allow for ... appending.
     */
    const FRAG_SIZE = 510;

    /**
     * Marker for the start of a highlight.
     */
    const HIGHLIGHT_START = '@@HI_S@@';

    /**
     * Marker for the end of a highlight.
     */
    const HIGHLIGHT_END = '@@HI_E@@';

    /** @var float Boost value for matching course in location-ordered searches */
    const COURSE_BOOST = 1;

    /** @var float Boost value for matching context (in addition to course boost) */
    const CONTEXT_BOOST = 0.5;

    /**
     * @var \SolrClient
     */
    protected $client = null;

    /**
     * @var bool True if we should reuse SolrClients, false if not.
     */
    protected $cacheclient = true;

    /**
     * @var \curl Direct curl object.
     */
    protected $curl = null;

    /**
     * @var array Fields that can be highlighted.
     */
    protected $highlightfields = array('title', 'content', 'description1', 'description2');

    /**
     * @var int Number of total docs reported by Sorl for the last query.
     */
    protected $totalenginedocs = 0;

    /**
     * @var int Number of docs we have processed for the last query.
     */
    protected $processeddocs = 0;

    /**
     * @var int Number of docs that have been skipped while processing the last query.
     */
    protected $skippeddocs = 0;

    /**
     * Solr server major version.
     *
     * @var int
     */
    protected $solrmajorversion = null;

    /**
     * Initialises the search engine configuration.
     *
     * @param bool $alternateconfiguration If true, use alternate configuration settings
     * @return void
     */
    public function __construct(bool $alternateconfiguration = false) {
        parent::__construct($alternateconfiguration);

        $curlversion = curl_version();
        if (isset($curlversion['version']) && stripos($curlversion['version'], '7.35.') === 0) {
            // There is a flaw with curl 7.35.0 that causes problems with client reuse.
            $this->cacheclient = false;
        }
    }

    /**
     * Prepares a Solr query, applies filters and executes it returning its results.
     *
     * @throws \core_search\engine_exception
     * @param  \stdClass $filters Containing query and filters.
     * @param  \stdClass $accessinfo Information about areas user can access.
     * @param  int       $limit The maximum number of results to return.
     * @return \core_search\document[] Results or false if no results
     */
    public function execute_query($filters, $accessinfo, $limit = 0) {
        global $USER;

        if (empty($limit)) {
            $limit = \core_search\manager::MAX_RESULTS;
        }

        // If there is any problem we trigger the exception as soon as possible.
        $client = $this->get_search_client();

        // Create the query object.
        $query = $this->create_user_query($filters, $accessinfo);

        // If the query cannot have results, return none.
        if (!$query) {
            return [];
        }

        // We expect good match rates, so for our first get, we will get a small number of records.
        // This significantly speeds solr response time for first few pages.
        $query->setRows(min($limit * 3, static::QUERY_SIZE));
        $response = $this->get_query_response($query);

        // Get count data out of the response, and reset our counters.
        list($included, $found) = $this->get_response_counts($response);
        $this->totalenginedocs = $found;
        $this->processeddocs = 0;
        $this->skippeddocs = 0;
        if ($included == 0 || $this->totalenginedocs == 0) {
            // No results.
            return array();
        }

        // Get valid documents out of the response.
        $results = $this->process_response($response, $limit);

        // We have processed all the docs in the response at this point.
        $this->processeddocs += $included;

        // If we haven't reached the limit, and there are more docs left in Solr, lets keep trying.
        while (count($results) < $limit && ($this->totalenginedocs - $this->processeddocs) > 0) {
            // Offset the start of the query, and since we are making another call, get more per call.
            $query->setStart($this->processeddocs);
            $query->setRows(static::QUERY_SIZE);

            $response = $this->get_query_response($query);
            list($included, $found) = $this->get_response_counts($response);
            if ($included == 0 || $found == 0) {
                // No new results were found. Found being empty would be weird, so we will just return.
                return $results;
            }
            $this->totalenginedocs = $found;

            // Get the new response docs, limiting to remaining we need, then add it to the end of the results array.
            $newdocs = $this->process_response($response, $limit - count($results));
            $results = array_merge($results, $newdocs);

            // Add to our processed docs count.
            $this->processeddocs += $included;
        }

        return $results;
    }

    /**
     * Takes a query and returns the response in SolrObject format.
     *
     * @param  SolrQuery  $query Solr query object.
     * @return SolrObject|false Response document or false on error.
     */
    protected function get_query_response($query) {
        try {
            return $this->get_search_client()->query($query)->getResponse();
        } catch (\SolrClientException $ex) {
            debugging('Error executing the provided query: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $this->queryerror = $ex->getMessage();
            return false;
        } catch (\SolrServerException $ex) {
            debugging('Error executing the provided query: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $this->queryerror = $ex->getMessage();
            return false;
        }
    }

    /**
     * Returns the total number of documents available for the most recently call to execute_query.
     *
     * @return int
     */
    public function get_query_total_count() {
        // Return the total engine count minus the docs we have determined are bad.
        return $this->totalenginedocs - $this->skippeddocs;
    }

    /**
     * Returns count information for a provided response. Will return 0, 0 for invalid or empty responses.
     *
     * @param SolrDocument $response The response document from Solr.
     * @return array A two part array. First how many response docs are in the response.
     *               Second, how many results are vailable in the engine.
     */
    protected function get_response_counts($response) {
        $found = 0;
        $included = 0;

        if (isset($response->grouped->solr_filegroupingid->ngroups)) {
            // Get the number of results for file grouped queries.
            $found = $response->grouped->solr_filegroupingid->ngroups;
            $included = count($response->grouped->solr_filegroupingid->groups);
        } else if (isset($response->response->numFound)) {
            // Get the number of results for standard queries.
            $found = $response->response->numFound;
            if ($found > 0 && is_array($response->response->docs)) {
                $included = count($response->response->docs);
            }
        }

        return array($included, $found);
    }

    /**
     * Prepares a new query object with needed limits, filters, etc.
     *
     * @param \stdClass $filters Containing query and filters.
     * @param \stdClass $accessinfo Information about contexts the user can access
     * @return \SolrDisMaxQuery|null Query object or null if they can't get any results
     */
    protected function create_user_query($filters, $accessinfo) {
        global $USER;

        // Let's keep these changes internal.
        $data = clone $filters;

        $query = new \SolrDisMaxQuery();

        $this->set_query($query, self::replace_underlines($data->q));
        $this->add_fields($query);

        // Search filters applied, we don't cache these filters as we don't want to pollute the cache with tmp filters
        // we are really interested in caching contexts filters instead.
        if (!empty($data->title)) {
            $query->addFilterQuery('{!field cache=false f=title}' . $data->title);
        }
        if (!empty($data->areaids)) {
            // If areaids are specified, we want to get any that match.
            $query->addFilterQuery('{!cache=false}areaid:(' . implode(' OR ', $data->areaids) . ')');
        }
        if (!empty($data->courseids)) {
            $query->addFilterQuery('{!cache=false}courseid:(' . implode(' OR ', $data->courseids) . ')');
        }
        if (!empty($data->groupids)) {
            $query->addFilterQuery('{!cache=false}groupid:(' . implode(' OR ', $data->groupids) . ')');
        }
        if (!empty($data->userids)) {
            $query->addFilterQuery('{!cache=false}userid:(' . implode(' OR ', $data->userids) . ')');
        }

        if (!empty($data->timestart) or !empty($data->timeend)) {
            if (empty($data->timestart)) {
                $data->timestart = '*';
            } else {
                $data->timestart = \search_solr\document::format_time_for_engine($data->timestart);
            }
            if (empty($data->timeend)) {
                $data->timeend = '*';
            } else {
                $data->timeend = \search_solr\document::format_time_for_engine($data->timeend);
            }

            // No cache.
            $query->addFilterQuery('{!cache=false}modified:[' . $data->timestart . ' TO ' . $data->timeend . ']');
        }

        // Restrict to users who are supposed to be able to see a particular result.
        $query->addFilterQuery('owneruserid:(' . \core_search\manager::NO_OWNER_ID . ' OR ' . $USER->id . ')');

        // And finally restrict it to the context where the user can access, we want this one cached.
        // If the user can access all contexts $usercontexts value is just true, we don't need to filter
        // in that case.
        if (!$accessinfo->everything && is_array($accessinfo->usercontexts)) {
            // Join all area contexts into a single array and implode.
            $allcontexts = array();
            foreach ($accessinfo->usercontexts as $areaid => $areacontexts) {
                if (!empty($data->areaids) && !in_array($areaid, $data->areaids)) {
                    // Skip unused areas.
                    continue;
                }
                foreach ($areacontexts as $contextid) {
                    // Ensure they are unique.
                    $allcontexts[$contextid] = $contextid;
                }
            }
            if (empty($allcontexts)) {
                // This means there are no valid contexts for them, so they get no results.
                return null;
            }
            $query->addFilterQuery('contextid:(' . implode(' OR ', $allcontexts) . ')');
        }

        if (!$accessinfo->everything && $accessinfo->separategroupscontexts) {
            // Add another restriction to handle group ids. If there are any contexts using separate
            // groups, then results in that context will not show unless you belong to the group.
            // (Note: Access all groups is taken care of earlier, when computing these arrays.)

            // This special exceptions list allows for particularly pig-headed developers to create
            // multiple search areas within the same module, where one of them uses separate
            // groups and the other uses visible groups. It is a little inefficient, but this should
            // be rare.
            $exceptions = '';
            if ($accessinfo->visiblegroupscontextsareas) {
                foreach ($accessinfo->visiblegroupscontextsareas as $contextid => $areaids) {
                    $exceptions .= ' OR (contextid:' . $contextid . ' AND areaid:(' .
                            implode(' OR ', $areaids) . '))';
                }
            }

            if ($accessinfo->usergroups) {
                // Either the document has no groupid, or the groupid is one that the user
                // belongs to, or the context is not one of the separate groups contexts.
                $query->addFilterQuery('(*:* -groupid:[* TO *]) OR ' .
                        'groupid:(' . implode(' OR ', $accessinfo->usergroups) . ') OR ' .
                        '(*:* -contextid:(' . implode(' OR ', $accessinfo->separategroupscontexts) . '))' .
                        $exceptions);
            } else {
                // Either the document has no groupid, or the context is not a restricted one.
                $query->addFilterQuery('(*:* -groupid:[* TO *]) OR ' .
                        '(*:* -contextid:(' . implode(' OR ', $accessinfo->separategroupscontexts) . '))' .
                        $exceptions);
            }
        }

        if ($this->file_indexing_enabled()) {
            // Now group records by solr_filegroupingid. Limit to 3 results per group.
            $query->setGroup(true);
            $query->setGroupLimit(3);
            $query->setGroupNGroups(true);
            $query->addGroupField('solr_filegroupingid');
        } else {
            // Make sure we only get text files, in case the index has pre-existing files.
            $query->addFilterQuery('type:'.\core_search\manager::TYPE_TEXT);
        }

        // If ordering by location, add in boost for the relevant course or context ids.
        if (!empty($filters->order) && $filters->order === 'location') {
            $coursecontext = $filters->context->get_course_context();
            $query->addBoostQuery('courseid', $coursecontext->instanceid, self::COURSE_BOOST);
            if ($filters->context->contextlevel !== CONTEXT_COURSE) {
                // If it's a block or activity, also add a boost for the specific context id.
                $query->addBoostQuery('contextid', $filters->context->id, self::CONTEXT_BOOST);
            }
        }

        return $query;
    }

    /**
     * Prepares a new query by setting the query, start offset and rows to return.
     *
     * @param SolrQuery $query
     * @param object    $q Containing query and filters.
     */
    protected function set_query($query, $q) {
        // Set hightlighting.
        $query->setHighlight(true);
        foreach ($this->highlightfields as $field) {
            $query->addHighlightField($field);
        }
        $query->setHighlightFragsize(static::FRAG_SIZE);
        $query->setHighlightSimplePre(self::HIGHLIGHT_START);
        $query->setHighlightSimplePost(self::HIGHLIGHT_END);
        $query->setHighlightMergeContiguous(true);

        $query->setQuery($q);

        // A reasonable max.
        $query->setRows(static::QUERY_SIZE);
    }

    /**
     * Sets fields to be returned in the result.
     *
     * @param SolrDisMaxQuery|SolrQuery $query object.
     */
    public function add_fields($query) {
        $documentclass = $this->get_document_classname();
        $fields = $documentclass::get_default_fields_definition();

        $dismax = false;
        if ($query instanceof \SolrDisMaxQuery) {
            $dismax = true;
        }

        foreach ($fields as $key => $field) {
            $query->addField($key);
            if ($dismax && !empty($field['mainquery'])) {
                // Add fields the main query should be run against.
                // Due to a regression in the PECL solr extension, https://bugs.php.net/bug.php?id=72740,
                // a boost value is required, even if it is optional; to avoid boosting one among other fields,
                // the explicit boost value will be the default one, for every field.
                $query->addQueryField($key, 1);
            }
        }
    }

    /**
     * Finds the key common to both highlighing and docs array returned from response.
     * @param object $response containing results.
     */
    public function add_highlight_content($response) {
        if (!isset($response->highlighting)) {
            // There is no highlighting to add.
            return;
        }

        $highlightedobject = $response->highlighting;
        foreach ($response->response->docs as $doc) {
            $x = $doc->id;
            $highlighteddoc = $highlightedobject->$x;
            $this->merge_highlight_field_values($doc, $highlighteddoc);
        }
    }

    /**
     * Adds the highlighting array values to docs array values.
     *
     * @throws \core_search\engine_exception
     * @param object $doc containing the results.
     * @param object $highlighteddoc containing the highlighted results values.
     */
    public function merge_highlight_field_values($doc, $highlighteddoc) {

        foreach ($this->highlightfields as $field) {
            if (!empty($doc->$field)) {

                // Check that the returned value is not an array. No way we can make this work with multivalued solr fields.
                if (is_array($doc->{$field})) {
                    throw new \core_search\engine_exception('multivaluedfield', 'search_solr', '', $field);
                }

                if (!empty($highlighteddoc->$field)) {
                    // Replace by the highlighted result.
                    $doc->$field = reset($highlighteddoc->$field);
                }
            }
        }
    }

    /**
     * Filters the response on Moodle side.
     *
     * @param SolrObject $response Solr object containing the response return from solr server.
     * @param int        $limit The maximum number of results to return. 0 for all.
     * @param bool       $skipaccesscheck Don't use check_access() on results. Only to be used when results have known access.
     * @return array $results containing final results to be displayed.
     */
    protected function process_response($response, $limit = 0, $skipaccesscheck = false) {
        global $USER;

        if (empty($response)) {
            return array();
        }

        if (isset($response->grouped)) {
            return $this->grouped_files_process_response($response, $limit);
        }

        $userid = $USER->id;
        $noownerid = \core_search\manager::NO_OWNER_ID;

        $numgranted = 0;

        if (!$docs = $response->response->docs) {
            return array();
        }

        $out = array();
        if (!empty($response->response->numFound)) {
            $this->add_highlight_content($response);

            // Iterate through the results checking its availability and whether they are available for the user or not.
            foreach ($docs as $key => $docdata) {
                if ($docdata['owneruserid'] != $noownerid && $docdata['owneruserid'] != $userid) {
                    // If owneruserid is set, no other user should be able to access this record.
                    continue;
                }

                if (!$searcharea = $this->get_search_area($docdata->areaid)) {
                    continue;
                }

                $docdata = $this->standarize_solr_obj($docdata);

                if ($skipaccesscheck) {
                    $access = \core_search\manager::ACCESS_GRANTED;
                } else {
                    $access = $searcharea->check_access($docdata['itemid']);
                }
                switch ($access) {
                    case \core_search\manager::ACCESS_DELETED:
                        $this->delete_by_id($docdata['id']);
                        // Remove one from our processed and total counters, since we promptly deleted.
                        $this->processeddocs--;
                        $this->totalenginedocs--;
                        break;
                    case \core_search\manager::ACCESS_DENIED:
                        $this->skippeddocs++;
                        break;
                    case \core_search\manager::ACCESS_GRANTED:
                        $numgranted++;

                        // Add the doc.
                        $out[] = $this->to_document($searcharea, $docdata);
                        break;
                }

                // Stop when we hit our limit.
                if (!empty($limit) && count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Processes grouped file results into documents, with attached matching files.
     *
     * @param SolrObject $response The response returned from solr server
     * @param int        $limit The maximum number of results to return. 0 for all.
     * @return array Final results to be displayed.
     */
    protected function grouped_files_process_response($response, $limit = 0) {
        // If we can't find the grouping, or there are no matches in the grouping, return empty.
        if (!isset($response->grouped->solr_filegroupingid) || empty($response->grouped->solr_filegroupingid->matches)) {
            return array();
        }

        $numgranted = 0;
        $orderedids = array();
        $completedocs = array();
        $incompletedocs = array();

        $highlightingobj = $response->highlighting;

        // Each group represents a "master document".
        $groups = $response->grouped->solr_filegroupingid->groups;
        foreach ($groups as $group) {
            $groupid = $group->groupValue;
            $groupdocs = $group->doclist->docs;
            $firstdoc = reset($groupdocs);

            if (!$searcharea = $this->get_search_area($firstdoc->areaid)) {
                // Well, this is a problem.
                continue;
            }

            // Check for access.
            $access = $searcharea->check_access($firstdoc->itemid);
            switch ($access) {
                case \core_search\manager::ACCESS_DELETED:
                    // If deleted from Moodle, delete from index and then continue.
                    $this->delete_by_id($firstdoc->id);
                    // Remove one from our processed and total counters, since we promptly deleted.
                    $this->processeddocs--;
                    $this->totalenginedocs--;
                    continue 2;
                    break;
                case \core_search\manager::ACCESS_DENIED:
                    // This means we should just skip for the current user.
                    $this->skippeddocs++;
                    continue 2;
                    break;
            }
            $numgranted++;

            $maindoc = false;
            $fileids = array();
            // Seperate the main document and any files returned.
            foreach ($groupdocs as $groupdoc) {
                if ($groupdoc->id == $groupid) {
                    $maindoc = $groupdoc;
                } else if (isset($groupdoc->solr_fileid)) {
                    $fileids[] = $groupdoc->solr_fileid;
                }
            }

            // Store the id of this group, in order, for later merging.
            $orderedids[] = $groupid;

            if (!$maindoc) {
                // We don't have the main doc, store what we know for later building.
                $incompletedocs[$groupid] = $fileids;
            } else {
                if (isset($highlightingobj->$groupid)) {
                    // Merge the highlighting for this doc.
                    $this->merge_highlight_field_values($maindoc, $highlightingobj->$groupid);
                }
                $docdata = $this->standarize_solr_obj($maindoc);
                $doc = $this->to_document($searcharea, $docdata);
                // Now we need to attach the result files to the doc.
                foreach ($fileids as $fileid) {
                    $doc->add_stored_file($fileid);
                }
                $completedocs[$groupid] = $doc;
            }

            if (!empty($limit) && $numgranted >= $limit) {
                // We have hit the max results, we will just ignore the rest.
                break;
            }
        }

        $incompletedocs = $this->get_missing_docs($incompletedocs);

        $out = array();
        // Now merge the complete and incomplete documents, in results order.
        foreach ($orderedids as $docid) {
            if (isset($completedocs[$docid])) {
                $out[] = $completedocs[$docid];
            } else if (isset($incompletedocs[$docid])) {
                $out[] = $incompletedocs[$docid];
            }
        }

        return $out;
    }

    /**
     * Retreive any missing main documents and attach provided files.
     *
     * The missingdocs array should be an array, indexed by document id, of main documents we need to retrieve. The value
     * associated to the key should be an array of stored_files or stored file ids to attach to the result document.
     *
     * Return array also indexed by document id.
     *
     * @param array() $missingdocs An array, indexed by document id, with arrays of files/ids to attach.
     * @return document[]
     */
    protected function get_missing_docs($missingdocs) {
        if (empty($missingdocs)) {
            return array();
        }

        $docids = array_keys($missingdocs);

        // Build a custom query that will get all the missing documents.
        $query = new \SolrQuery();
        $this->set_query($query, '*');
        $this->add_fields($query);
        $query->setRows(count($docids));
        $query->addFilterQuery('{!cache=false}id:(' . implode(' OR ', $docids) . ')');

        $response = $this->get_query_response($query);
        // We know the missing docs have already been checked for access, so don't recheck.
        $results = $this->process_response($response, 0, true);

        $out = array();
        foreach ($results as $result) {
            $resultid = $result->get('id');
            if (!isset($missingdocs[$resultid])) {
                // We got a result we didn't expect. Skip it.
                continue;
            }
            // Attach the files.
            foreach ($missingdocs[$resultid] as $filedoc) {
                $result->add_stored_file($filedoc);
            }
            $out[$resultid] = $result;
        }

        return $out;
    }

    /**
     * Returns a standard php array from a \SolrObject instance.
     *
     * @param \SolrObject $obj
     * @return array The returned document as an array.
     */
    public function standarize_solr_obj(\SolrObject $obj) {
        $properties = $obj->getPropertyNames();

        $docdata = array();
        foreach($properties as $name) {
            // http://php.net/manual/en/solrobject.getpropertynames.php#98018.
            $name = trim($name);
            $docdata[$name] = $obj->offsetGet($name);
        }
        return $docdata;
    }

    /**
     * Adds a document to the search engine.
     *
     * This does not commit to the search engine.
     *
     * @param document $document
     * @param bool     $fileindexing True if file indexing is to be used
     * @return bool
     */
    public function add_document($document, $fileindexing = false) {
        $docdata = $document->export_for_engine();

        if (!$this->add_solr_document($docdata)) {
            return false;
        }

        if ($fileindexing) {
            // This will take care of updating all attached files in the index.
            $this->process_document_files($document);
        }

        return true;
    }

    /**
     * Adds a batch of documents to the engine at once.
     *
     * @param \core_search\document[] $documents Documents to add
     * @param bool $fileindexing If true, indexes files (these are done one at a time)
     * @return int[] Array of three elements: successfully processed, failed processed, batch count
     */
    public function add_document_batch(array $documents, bool $fileindexing = false): array {
        $docdatabatch = [];
        foreach ($documents as $document) {
            $docdatabatch[] = $document->export_for_engine();
        }

        $resultcounts = $this->add_solr_documents($docdatabatch);

        // Files are processed one document at a time (if there are files it's slow anyway).
        if ($fileindexing) {
            foreach ($documents as $document) {
                // This will take care of updating all attached files in the index.
                $this->process_document_files($document);
            }
        }

        return $resultcounts;
    }

    /**
     * Replaces underlines at edges of words in the content with spaces.
     *
     * For example '_frogs_' will become 'frogs', '_frogs and toads_' will become 'frogs and toads',
     * and 'frogs_and_toads' will be left as 'frogs_and_toads'.
     *
     * The reason for this is that for italic content_to_text puts _italic_ underlines at the start
     * and end of the italicised phrase (not between words). Solr treats underlines as part of the
     * word, which means that if you search for a word in italic then you can't find it.
     *
     * @param string $str String to replace
     * @return string Replaced string
     */
    protected static function replace_underlines(string $str): string {
        return preg_replace('~\b_|_\b~', '', $str);
    }

    /**
     * Creates a Solr document object.
     *
     * @param array $doc Array of document fields
     * @return \SolrInputDocument Created document
     */
    protected function create_solr_document(array $doc): \SolrInputDocument {
        $solrdoc = new \SolrInputDocument();

        // Replace underlines in the content with spaces. The reason for this is that for italic
        // text, content_to_text puts _italic_ underlines. Solr treats underlines as part of the
        // word, which means that if you search for a word in italic then you can't find it.
        if (array_key_exists('content', $doc)) {
            $doc['content'] = self::replace_underlines($doc['content']);
        }

        // Set all the fields.
        foreach ($doc as $field => $value) {
            $solrdoc->addField($field, $value);
        }

        return $solrdoc;
    }

    /**
     * Adds a text document to the search engine.
     *
     * @param array $doc
     * @return bool
     */
    protected function add_solr_document($doc) {
        $solrdoc = $this->create_solr_document($doc);

        try {
            $result = $this->get_search_client()->addDocument($solrdoc, true, static::AUTOCOMMIT_WITHIN);
            return true;
        } catch (\SolrClientException $e) {
            debugging('Solr client error adding document with id ' . $doc['id'] . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        } catch (\SolrServerException $e) {
            // We only use the first line of the message, as it's a fully java stacktrace behind it.
            $msg = strtok($e->getMessage(), "\n");
            debugging('Solr server error adding document with id ' . $doc['id'] . ': ' . $msg, DEBUG_DEVELOPER);
        }

        return false;
    }

    /**
     * Adds multiple text documents to the search engine.
     *
     * @param array $docs Array of documents (each an array of fields) to add
     * @return int[] Array of success, failure, batch count
     * @throws \core_search\engine_exception
     */
    protected function add_solr_documents(array $docs): array {
        $solrdocs = [];
        foreach ($docs as $doc) {
            $solrdocs[] = $this->create_solr_document($doc);
        }

        try {
            // Add documents in a batch and report that they all succeeded.
            $this->get_search_client()->addDocuments($solrdocs, true, static::AUTOCOMMIT_WITHIN);
            return [count($solrdocs), 0, 1];
        } catch (\SolrClientException $e) {
            // If there is an exception, fall through...
            $donothing = true;
        } catch (\SolrServerException $e) {
            // If there is an exception, fall through...
            $donothing = true;
        }

        // When there is an error, we fall back to adding them individually so that we can report
        // which document(s) failed. Since it overwrites, adding the successful ones multiple
        // times won't hurt.
        $success = 0;
        $failure = 0;
        $batches = 0;
        foreach ($docs as $doc) {
            $result = $this->add_solr_document($doc);
            $batches++;
            if ($result) {
                $success++;
            } else {
                $failure++;
            }
        }

        return [$success, $failure, $batches];
    }

    /**
     * Index files attached to the docuemnt, ensuring the index matches the current document files.
     *
     * For documents that aren't known to be new, we check the index for existing files.
     * - New files we will add.
     * - Existing and unchanged files we will skip.
     * - File that are in the index but not on the document will be deleted from the index.
     * - Files that have changed will be re-indexed.
     *
     * @param document $document
     */
    protected function process_document_files($document) {
        if (!$this->file_indexing_enabled()) {
            return;
        }

        // Maximum rows to process at a time.
        $rows = 500;

        // Get the attached files.
        $files = $document->get_files();

        // If this isn't a new document, we need to check the exiting indexed files.
        if (!$document->get_is_new()) {
            // We do this progressively, so we can handle lots of files cleanly.
            list($numfound, $indexedfiles) = $this->get_indexed_files($document, 0, $rows);
            $count = 0;
            $idstodelete = array();

            do {
                // Go through each indexed file. We want to not index any stored and unchanged ones, delete any missing ones.
                foreach ($indexedfiles as $indexedfile) {
                    $fileid = $indexedfile->solr_fileid;

                    if (isset($files[$fileid])) {
                        // Check for changes that would mean we need to re-index the file. If so, just leave in $files.
                        // Filelib does not guarantee time modified is updated, so we will check important values.
                        if ($indexedfile->modified != $files[$fileid]->get_timemodified()) {
                            continue;
                        }
                        if (strcmp($indexedfile->title, $files[$fileid]->get_filename()) !== 0) {
                            continue;
                        }
                        if ($indexedfile->solr_filecontenthash != $files[$fileid]->get_contenthash()) {
                            continue;
                        }
                        if ($indexedfile->solr_fileindexstatus == document::INDEXED_FILE_FALSE &&
                                $this->file_is_indexable($files[$fileid])) {
                            // This means that the last time we indexed this file, filtering blocked it.
                            // Current settings say it is indexable, so we will allow it to be indexed.
                            continue;
                        }

                        // If the file is already indexed, we can just remove it from the files array and skip it.
                        unset($files[$fileid]);
                    } else {
                        // This means we have found a file that is no longer attached, so we need to delete from the index.
                        // We do it later, since this is progressive, and it could reorder results.
                        $idstodelete[] = $indexedfile->id;
                    }
                }
                $count += $rows;

                if ($count < $numfound) {
                    // If we haven't hit the total count yet, fetch the next batch.
                    list($numfound, $indexedfiles) = $this->get_indexed_files($document, $count, $rows);
                }

            } while ($count < $numfound);

            // Delete files that are no longer attached.
            foreach ($idstodelete as $id) {
                // We directly delete the item using the client, as the engine delete_by_id won't work on file docs.
                $this->get_search_client()->deleteById($id);
            }
        }

        // Now we can actually index all the remaining files.
        foreach ($files as $file) {
            $this->add_stored_file($document, $file);
        }
    }

    /**
     * Get the currently indexed files for a particular document, returns the total count, and a subset of files.
     *
     * @param document $document
     * @param int      $start The row to start the results on. Zero indexed.
     * @param int      $rows The number of rows to fetch
     * @return array   A two element array, the first is the total number of availble results, the second is an array
     *                 of documents for the current request.
     */
    protected function get_indexed_files($document, $start = 0, $rows = 500) {
        // Build a custom query that will get any document files that are in our solr_filegroupingid.
        $query = new \SolrQuery();

        // We want to get all file records tied to a document.
        // For efficiency, we are building our own, stripped down, query.
        $query->setQuery('*');
        $query->setRows($rows);
        $query->setStart($start);
        // We want a consistent sorting.
        $query->addSortField('id');

        // We only want the bare minimum of fields.
        $query->addField('id');
        $query->addField('modified');
        $query->addField('title');
        $query->addField('solr_fileid');
        $query->addField('solr_filecontenthash');
        $query->addField('solr_fileindexstatus');

        $query->addFilterQuery('{!cache=false}solr_filegroupingid:(' . $document->get('id') . ')');
        $query->addFilterQuery('type:' . \core_search\manager::TYPE_FILE);

        $response = $this->get_query_response($query);
        if (empty($response->response->numFound)) {
            return array(0, array());
        }

        return array($response->response->numFound, $this->convert_file_results($response));
    }

    /**
     * A very lightweight handler for getting information about already indexed files from a Solr response.
     *
     * @param SolrObject $responsedoc A Solr response document
     * @return stdClass[] An array of objects that contain the basic information for file processing.
     */
    protected function convert_file_results($responsedoc) {
        if (!$docs = $responsedoc->response->docs) {
            return array();
        }

        $out = array();

        foreach ($docs as $doc) {
            // Copy the bare minimim needed info.
            $result = new \stdClass();
            $result->id = $doc->id;
            $result->modified = document::import_time_from_engine($doc->modified);
            $result->title = $doc->title;
            $result->solr_fileid = $doc->solr_fileid;
            $result->solr_filecontenthash = $doc->solr_filecontenthash;
            $result->solr_fileindexstatus = $doc->solr_fileindexstatus;
            $out[] = $result;
        }

        return $out;
    }

    /**
     * Adds a file to the search engine.
     *
     * Notes about Solr and Tika indexing. We do not send the mime type, only the filename.
     * Tika has much better content type detection than Moodle, and we will have many more doc failures
     * if we try to send mime types.
     *
     * @param document $document
     * @param \stored_file $storedfile
     * @return void
     */
    protected function add_stored_file($document, $storedfile) {
        $filedoc = $document->export_file_for_engine($storedfile);

        if (!$this->file_is_indexable($storedfile)) {
            // For files that we don't consider indexable, we will still place a reference in the search engine.
            $filedoc['solr_fileindexstatus'] = document::INDEXED_FILE_FALSE;
            $this->add_solr_document($filedoc);
            return;
        }

        $curl = $this->get_curl_object();

        $url = $this->get_connection_url('/update/extract');

        // Return results as XML.
        $url->param('wt', 'xml');

        // This will prevent solr from automatically making fields for every tika output.
        $url->param('uprefix', 'ignored_');

        // Control how content is captured. This will keep our file content clean of non-important metadata.
        $url->param('captureAttr', 'true');
        // Move the content to a field for indexing.
        $url->param('fmap.content', 'solr_filecontent');

        // These are common fields that matches the standard *_point dynamic field and causes an error.
        $url->param('fmap.media_white_point', 'ignored_mwp');
        $url->param('fmap.media_black_point', 'ignored_mbp');

        // Copy each key to the url with literal.
        // We place in a temp name then copy back to the true field, which prevents errors or Tika overwriting common field names.
        foreach ($filedoc as $key => $value) {
            // This will take any fields from tika that match our schema and discard them, so they don't overwrite ours.
            $url->param('fmap.'.$key, 'ignored_'.$key);
            // Place data in a tmp field.
            $url->param('literal.mdltmp_'.$key, $value);
            // Then move to the final field.
            $url->param('fmap.mdltmp_'.$key, $key);
        }

        // This sets the true filename for Tika.
        $url->param('resource.name', $storedfile->get_filename());

        // A giant block of code that is really just error checking around the curl request.
        try {
            // We have to post the file directly in binary data (not using multipart) to avoid
            // Solr bug SOLR-15039 which can cause incorrect data when you use multipart upload.
            // Note this loads the whole file into memory; see limit in file_is_indexable().
            $result = $curl->post($url->out(false), $storedfile->get_content());

            $code = $curl->get_errno();
            $info = $curl->get_info();

            // Now error handling. It is just informational, since we aren't tracking per file/doc results.
            if ($code != 0) {
                // This means an internal cURL error occurred error is in result.
                $message = 'Curl error '.$code.' while indexing file with document id '.$filedoc['id'].': '.$result.'.';
                debugging($message, DEBUG_DEVELOPER);
            } else if (isset($info['http_code']) && ($info['http_code'] !== 200)) {
                // Unexpected HTTP response code.
                $message = 'Error while indexing file with document id '.$filedoc['id'];
                // Try to get error message out of msg or title if it exists.
                if (preg_match('|<str [^>]*name="msg"[^>]*>(.*?)</str>|i', $result, $matches)) {
                    $message .= ': '.$matches[1];
                } else if (preg_match('|<title[^>]*>([^>]*)</title>|i', $result, $matches)) {
                    $message .= ': '.$matches[1];
                }
                // This is a common error, happening whenever a file fails to index for any reason, so we will make it quieter.
                if (CLI_SCRIPT && !PHPUNIT_TEST) {
                    mtrace($message);
                }
            } else {
                // Check for the expected status field.
                if (preg_match('|<int [^>]*name="status"[^>]*>(\d*)</int>|i', $result, $matches)) {
                    // Now check for the expected status of 0, if not, error.
                    if ((int)$matches[1] !== 0) {
                        $message = 'Unexpected Solr status code '.(int)$matches[1];
                        $message .= ' while indexing file with document id '.$filedoc['id'].'.';
                        debugging($message, DEBUG_DEVELOPER);
                    } else {
                        // The document was successfully indexed.
                        return;
                    }
                } else {
                    // We received an unprocessable response.
                    $message = 'Unexpected Solr response while indexing file with document id '.$filedoc['id'].': ';
                    $message .= strtok($result, "\n");
                    debugging($message, DEBUG_DEVELOPER);
                }
            }
        } catch (\Exception $e) {
            // There was an error, but we are not tracking per-file success, so we just continue on.
            debugging('Unknown exception while indexing file "'.$storedfile->get_filename().'".', DEBUG_DEVELOPER);
        }

        // If we get here, the document was not indexed due to an error. So we will index just the base info without the file.
        $filedoc['solr_fileindexstatus'] = document::INDEXED_FILE_ERROR;
        $this->add_solr_document($filedoc);
    }

    /**
     * Checks to see if a passed file is indexable.
     *
     * @param \stored_file $file The file to check
     * @return bool True if the file can be indexed
     */
    protected function file_is_indexable($file) {
        if (!empty($this->config->maxindexfilekb) && ($file->get_filesize() > ($this->config->maxindexfilekb * 1024))) {
            // The file is too big to index.
            return false;
        }

        // Because we now load files into memory to index them in Solr, we also have to ensure that
        // we don't try to index anything bigger than the memory limit (less 100MB for safety).
        // Memory limit in cron is MEMORY_EXTRA which is usually 256 or 384MB but can be increased
        // in config, so this will allow files over 100MB to be indexed.
        $limit = ini_get('memory_limit');
        if ($limit && $limit != -1) {
            $limitbytes = get_real_size($limit);
            if ($file->get_filesize() > $limitbytes) {
                return false;
            }
        }

        $mime = $file->get_mimetype();

        if ($mime == 'application/vnd.moodle.backup') {
            // We don't index Moodle backup files. There is nothing usefully indexable in them.
            return false;
        }

        return true;
    }

    /**
     * Commits all pending changes.
     *
     * @return void
     */
    protected function commit() {
        $this->get_search_client()->commit();
    }

    /**
     * Do any area cleanup needed, and do anything to confirm contents.
     *
     * Return false to prevent the search area completed time and stats from being updated.
     *
     * @param \core_search\base $searcharea The search area that was complete
     * @param int $numdocs The number of documents that were added to the index
     * @param bool $fullindex True if a full index is being performed
     * @return bool True means that data is considered indexed
     */
    public function area_index_complete($searcharea, $numdocs = 0, $fullindex = false) {
        $this->commit();

        return true;
    }

    /**
     * Return true if file indexing is supported and enabled. False otherwise.
     *
     * @return bool
     */
    public function file_indexing_enabled() {
        return (bool)$this->config->fileindexing;
    }

    /**
     * Deletes the specified document.
     *
     * @param string $id The document id to delete
     * @return void
     */
    public function delete_by_id($id) {
        // We need to make sure we delete the item and all related files, which can be done with solr_filegroupingid.
        $this->get_search_client()->deleteByQuery('solr_filegroupingid:' . $id);
        $this->commit();
    }

    /**
     * Delete all area's documents.
     *
     * @param string $areaid
     * @return void
     */
    public function delete($areaid = null) {
        if ($areaid) {
            $this->get_search_client()->deleteByQuery('areaid:' . $areaid);
        } else {
            $this->get_search_client()->deleteByQuery('*:*');
        }
        $this->commit();
    }

    /**
     * Pings the Solr server using search_solr config
     *
     * @return true|string Returns true if all good or an error string.
     */
    public function is_server_ready() {

        $configured = $this->is_server_configured();
        if ($configured !== true) {
            return $configured;
        }

        // As part of the above we have already checked that we can contact the server. For pages
        // where performance is important, we skip doing a full schema check as well.
        if ($this->should_skip_schema_check()) {
            return true;
        }

        // Update schema if required/possible.
        $schemalatest = $this->check_latest_schema();
        if ($schemalatest !== true) {
            return $schemalatest;
        }

        // Check that the schema is already set up.
        try {
            $schema = new schema($this);
            $schema->validate_setup();
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    /**
     * Is the solr server properly configured?.
     *
     * @return true|string Returns true if all good or an error string.
     */
    public function is_server_configured() {

        if (empty($this->config->server_hostname) || empty($this->config->indexname)) {
            return 'No solr configuration found';
        }

        if (!$client = $this->get_search_client(false)) {
            return get_string('engineserverstatus', 'search');
        }

        try {
            if ($this->get_solr_major_version() < 4) {
                // Minimum solr 4.0.
                return get_string('minimumsolr4', 'search_solr');
            }
        } catch (\SolrClientException $ex) {
            debugging('Solr client error: ' . html_to_text($ex->getMessage()), DEBUG_DEVELOPER);
            return get_string('engineserverstatus', 'search');
        } catch (\SolrServerException $ex) {
            debugging('Solr server error: ' . html_to_text($ex->getMessage()), DEBUG_DEVELOPER);
            return get_string('engineserverstatus', 'search');
        }

        return true;
    }

    /**
     * Returns the solr server major version.
     *
     * @return int
     */
    public function get_solr_major_version() {
        if ($this->solrmajorversion !== null) {
            return $this->solrmajorversion;
        }

        // We should really ping first the server to see if the specified indexname is valid but
        // we want to minimise solr server requests as they are expensive. system() emits a warning
        // if it can not connect to the configured index in the configured server.
        $systemdata = @$this->get_search_client()->system();
        $solrversion = $systemdata->getResponse()->offsetGet('lucene')->offsetGet('solr-spec-version');
        $this->solrmajorversion = intval(substr($solrversion, 0, strpos($solrversion, '.')));

        return $this->solrmajorversion;
    }

    /**
     * Checks if the PHP Solr extension is available.
     *
     * @return bool
     */
    public function is_installed() {
        return function_exists('solr_get_version');
    }

    /** @var int When using the capath option, we generate a bundle containing all the pem files, cached 10 mins. */
    const CA_PATH_CACHE_TIME = 600;

    /** @var int Expired cache files are deleted after this many seconds. */
    const CA_PATH_CACHE_DELETE_AFTER = 60;

    /**
     * Gets status of Solr server.
     *
     * The result has the following fields:
     * - connected - true if we got a valid JSON response from server
     * - foundcore - true if we found the core defined in config (this could be false if schema not set up)
     *
     * It may have these other fields:
     * - error - text if anything went wrong
     * - exception - if an exception was thrown
     * - indexsize - index size in bytes if we found what it is
     *
     * @param int $timeout Optional timeout in seconds, otherwise uses config value
     * @return array Array with information about status
     * @since Moodle 5.0
     */
    public function get_status($timeout = 0): array {
        $result = ['connected' => false, 'foundcore' => false];
        try {
            $options = [];
            if ($timeout) {
                $options['connect_timeout'] = $timeout;
                $options['read_timeout'] = $timeout;
            }
            $before = microtime(true);
            try {
                $response = $this->raw_get_request('admin/cores', $options);
            } finally {
                $result['time'] = microtime(true) - $before;
            }
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $result['error'] = 'Unsuccessful status code: ' . $status;
                return $result;
            }
            $decoded = json_decode($response->getBody()->getContents());
            if (!$decoded) {
                $result['error'] = 'Invalid JSON';
                return $result;
            }
            // Provided we get some valid JSON then probably Solr exists and is responding.
            // Any following errors we don't count as not connected (ERROR display in the check)
            // because maybe it happens if Solr changes their JSON format in a future version.
            $result['connected'] = true;
            if (!property_exists($decoded, 'status')) {
                $result['error'] = 'Unexpected JSON: no core status';
                return $result;
            }
            foreach ($decoded->status as $core) {
                $match = false;
                if (!property_exists($core, 'name')) {
                    $result['error'] = 'Unexpected JSON: core has no name';
                    return $result;
                }
                if ($core->name === $this->config->indexname) {
                    $match = true;
                }
                if (!$match && property_exists($core, 'cloud')) {
                    if (!property_exists($core->cloud, 'collection')) {
                        $result['error'] = 'Unexpected JSON: core cloud has no name';
                        return $result;
                    }
                    if ($core->cloud->collection === $this->config->indexname) {
                        $match = true;
                    }
                }

                if ($match) {
                    $result['foundcore'] = true;
                    if (!property_exists($core, 'index')) {
                        $result['error'] = 'Unexpected JSON: core has no index';
                        return $result;
                    }
                    if (!property_exists($core->index, 'sizeInBytes')) {
                        $result['error'] = 'Unexpected JSON: core index has no sizeInBytes';
                        return $result;
                    }
                    $result['indexsize'] = $core->index->sizeInBytes;
                    return $result;
                }
            }
            $result['error'] = 'Could not find core matching ' . $this->config->indexname;;
            return $result;
        } catch (\Throwable $t) {
            $result['error'] = 'Exception occurred: ' . $t->getMessage();
            $result['exception'] = $t;
            return $result;
        }
    }

    /**
     * Returns the solr client instance.
     *
     * We don't reuse SolrClient if we are on libcurl 7.35.0, due to a bug in that version of curl.
     *
     * @throws \core_search\engine_exception
     * @param bool $triggerexception
     * @return \SolrClient
     */
    protected function get_search_client($triggerexception = true) {
        global $CFG;

        // Type comparison as it is set to false if not available.
        if ($this->client !== null) {
            return $this->client;
        }

        $options = array(
            'hostname' => $this->config->server_hostname,
            'path'     => '/solr/' . $this->config->indexname,
            'login'    => !empty($this->config->server_username) ? $this->config->server_username : '',
            'password' => !empty($this->config->server_password) ? $this->config->server_password : '',
            'port'     => !empty($this->config->server_port) ? $this->config->server_port : '',
            'secure' => !empty($this->config->secure) ? true : false,
            'ssl_cert' => !empty($this->config->ssl_cert) ? $this->config->ssl_cert : '',
            'ssl_key' => !empty($this->config->ssl_key) ? $this->config->ssl_key : '',
            'ssl_keypassword' => !empty($this->config->ssl_keypassword) ? $this->config->ssl_keypassword : '',
            'ssl_cainfo' => !empty($this->config->ssl_cainfo) ? $this->config->ssl_cainfo : '',
            'ssl_capath' => !empty($this->config->ssl_capath) ? $this->config->ssl_capath : '',
            'timeout' => !empty($this->config->server_timeout) ? $this->config->server_timeout : '30'
        );

        if ($CFG->proxyhost && !is_proxybypass('http://' . $this->config->server_hostname . '/')) {
            $options['proxy_host'] = $CFG->proxyhost;
            if (!empty($CFG->proxyport)) {
                $options['proxy_port'] = $CFG->proxyport;
            }
            if (!empty($CFG->proxyuser) && !empty($CFG->proxypassword)) {
                $options['proxy_login'] = $CFG->proxyuser;
                $options['proxy_password'] = $CFG->proxypassword;
            }
        }

        if (!class_exists('\SolrClient')) {
            throw new \core_search\engine_exception('enginenotinstalled', 'search', '', 'solr');
        }

        $client = new \SolrClient($options);

        if ($client === false && $triggerexception) {
            throw new \core_search\engine_exception('engineserverstatus', 'search');
        }

        if ($this->cacheclient) {
            $this->client = $client;
        }

        return $client;
    }

    /**
     * Returns a curl object for conntecting to solr.
     *
     * @return \curl
     */
    public function get_curl_object() {
        if (!is_null($this->curl)) {
            return $this->curl;
        }

        // Connection to Solr is allowed to use 'localhost' and other potentially blocked hosts/ports.
        $this->curl = new \curl(['ignoresecurity' => true]);

        $options = array();
        // Build the SSL options. Based on pecl-solr and general testing.
        if (!empty($this->config->secure)) {
            if (!empty($this->config->ssl_cert)) {
                $options['CURLOPT_SSLCERT'] = $this->config->ssl_cert;
                $options['CURLOPT_SSLCERTTYPE'] = 'PEM';
            }

            if (!empty($this->config->ssl_key)) {
                $options['CURLOPT_SSLKEY'] = $this->config->ssl_key;
                $options['CURLOPT_SSLKEYTYPE'] = 'PEM';
            }

            if (!empty($this->config->ssl_keypassword)) {
                $options['CURLOPT_KEYPASSWD'] = $this->config->ssl_keypassword;
            }

            if (!empty($this->config->ssl_cainfo)) {
                $options['CURLOPT_CAINFO'] = $this->config->ssl_cainfo;
            }

            if (!empty($this->config->ssl_capath)) {
                $options['CURLOPT_CAPATH'] = $this->config->ssl_capath;
            }
        }

        // Set timeout as for Solr client.
        $options['CURLOPT_TIMEOUT'] = !empty($this->config->server_timeout) ? $this->config->server_timeout : '30';

        $this->curl->setopt($options);

        if (!empty($this->config->server_username) && !empty($this->config->server_password)) {
            $authorization = $this->config->server_username . ':' . $this->config->server_password;
            $this->curl->setHeader('Authorization: Basic ' . base64_encode($authorization));
        }

        return $this->curl;
    }

    /**
     * Return a Moodle url object for the raw server URL (containing all indexes).
     *
     * @param string $path The solr path to append.
     * @return \moodle_url
     */
    public function get_server_url(string $path): \moodle_url {
        // Must use the proper protocol, or SSL will fail.
        $protocol = !empty($this->config->secure) ? 'https' : 'http';
        $url = $protocol . '://' . rtrim($this->config->server_hostname, '/');
        if (!empty($this->config->server_port)) {
            $url .= ':' . $this->config->server_port;
        }
        $url .= '/solr/' . ltrim($path, '/');
        return new \moodle_url($url);
    }

    /**
     * Return a Moodle url object for the server connection including the search index.
     *
     * @param string $path The solr path to append.
     * @return \moodle_url
     */
    public function get_connection_url($path) {
        return $this->get_server_url($this->config->indexname . '/' . ltrim($path, '/'));
    }

    /**
     * Calls the Solr engine with a GET request (for things the Solr extension doesn't support).
     *
     * This has similar result to get_curl_object but uses the newer (mockable) Guzzle HTTP client.
     *
     * @param string $path URL path (after /solr/) e.g. 'admin/cores?action=STATUS&core=frog'
     * @param array $overrideoptions Optional array of Guzzle options, will override config
     * @return \Psr\Http\Message\ResponseInterface Response message from Guzzle
     * @throws \GuzzleHttp\Exception\GuzzleException If any problem connecting
     * @since Moodle 5.0
     */
    public function raw_get_request(
        string $path,
        array $overrideoptions = [],
    ): \Psr\Http\Message\ResponseInterface {
        $client = \core\di::get(\core\http_client::class);
        return $client->get(
            $this->get_server_url($path)->out(false),
            $this->get_http_client_options($overrideoptions),
        );
    }

    /**
     * Gets the \core\http_client options for a connection.
     *
     * @param array $overrideoptions Optional array to override some of the options
     * @return array Array of http_client options
     */
    protected function get_http_client_options(array $overrideoptions = []): array {
        $options = [
            'connect_timeout' => !empty($this->config->server_timeout) ? (int)$this->config->server_timeout : 30,
        ];
        $options['read_timeout'] = $options['connect_timeout'];
        if (!empty($this->config->server_username)) {
            $options['auth'] = [$this->config->server_username, $this->config->server_password];
        }
        if (!empty($this->config->ssl_cert)) {
            $options['cert'] = $this->config->ssl_cert;
        }
        if (!empty($this->config->ssl_key)) {
            if (!empty($this->config->ssl_keypassword)) {
                $options['ssl_key'] = [$this->config->ssl_key, $this->config->ssl_keypassword];
            } else {
                $options['ssl_key'] = $this->config->ssl_key;
            }
        }
        if (!empty($this->config->ssl_cainfo)) {
            $options['verify'] = $this->config->ssl_cainfo;
        } else if (!empty($this->config->ssl_capath)) {
            // Guzzle doesn't support a whole path of CA certs, so we have to make a single file
            // with all the *.pem files in that directory. It needs to be in filesystem so we can
            // use it directly, let's put it in local cache for 10 minutes.
            $cachefolder = make_localcache_directory('search_solr');
            $prefix = 'capath.' . sha1($this->config->ssl_capath);
            $now = \core\di::get(\core\clock::class)->time();
            $got = false;
            foreach (scandir($cachefolder) as $filename) {
                // You are not allowed to overwrite files in localcache folders so we use files
                // with the time in, and delete old files with a 1 minute delay to avoid race
                // conditions.
                if (preg_match('~^(.*)\.([0-9]+)$~', $filename, $matches)) {
                    [1 => $fileprefix, 2 => $time] = $matches;
                    $pathname = $cachefolder . '/' . $filename;
                    if ($time > $now - self::CA_PATH_CACHE_TIME && $fileprefix === $prefix) {
                        $options['verify'] = $pathname;
                        $got = true;
                        break;
                    } else if ($time <= $now - self::CA_PATH_CACHE_TIME - self::CA_PATH_CACHE_DELETE_AFTER) {
                        unlink($pathname);
                    }
                }
            }

            if (!$got) {
                // If we don't have it yet, we need to make the cached file.
                $allpems = '';
                foreach (scandir($this->config->ssl_capath) as $filename) {
                    if (preg_match('~\.pem$~', $filename)) {
                        $pathname = $this->config->ssl_capath . '/' . $filename;
                        $allpems .= file_get_contents($pathname) . "\n\n";
                    }
                }
                $pathname = $cachefolder . '/' . $prefix . '.' . $now;
                file_put_contents($pathname, $allpems);
                $options['verify'] = $pathname;
            }
        }

        // Apply other/overridden options.
        foreach ($overrideoptions as $name => $value) {
            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Solr includes group support in the execute_query function.
     *
     * @return bool True
     */
    public function supports_group_filtering() {
        return true;
    }

    protected function update_schema($oldversion, $newversion) {
        // Construct schema.
        $schema = new schema($this);
        $cansetup = $schema->can_setup_server();
        if ($cansetup !== true) {
            return $cansetup;
        }

        switch ($newversion) {
            // This version just requires a setup call to add new fields.
            case 2017091700:
                $setup = true;
                break;

            // If we don't know about the schema version we might not have implemented the
            // change correctly, so return.
            default:
                return get_string('schemaversionunknown', 'search');
        }

        if ($setup) {
            $schema->setup();
        }

        return true;
    }

    /**
     * Solr supports sort by location within course contexts or below.
     *
     * @param \context $context Context that the user requested search from
     * @return array Array from order name => display text
     */
    public function get_supported_orders(\context $context) {
        $orders = parent::get_supported_orders($context);

        // If not within a course, no other kind of sorting supported.
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            // Within a course or activity/block, support sort by location.
            $orders['location'] = get_string('order_location', 'search',
                    $context->get_context_name());
        }

        return $orders;
    }

    /**
     * Solr supports search by user id.
     *
     * @return bool True
     */
    public function supports_users() {
        return true;
    }

    /**
     * Solr supports adding documents in a batch.
     *
     * @return bool True
     */
    public function supports_add_document_batch(): bool {
        return true;
    }

    /**
     * Solr supports deleting the index for a context.
     *
     * @param int $oldcontextid Context that has been deleted
     * @return bool True to indicate that any data was actually deleted
     * @throws \core_search\engine_exception
     */
    public function delete_index_for_context(int $oldcontextid) {
        $client = $this->get_search_client();
        try {
            $client->deleteByQuery('contextid:' . $oldcontextid);
            $client->commit(true);
            return true;
        } catch (\Exception $e) {
            throw new \core_search\engine_exception('error_solr', 'search_solr', '', $e->getMessage());
        }
    }

    /**
     * Solr supports deleting the index for a course.
     *
     * @param int $oldcourseid
     * @return bool True to indicate that any data was actually deleted
     * @throws \core_search\engine_exception
     */
    public function delete_index_for_course(int $oldcourseid) {
        $client = $this->get_search_client();
        try {
            $client->deleteByQuery('courseid:' . $oldcourseid);
            $client->commit(true);
            return true;
        } catch (\Exception $e) {
            throw new \core_search\engine_exception('error_solr', 'search_solr', '', $e->getMessage());
        }
    }

    /**
     * Checks if an alternate configuration has been defined.
     *
     * @return bool True if alternate configuration is available
     */
    public function has_alternate_configuration(): bool {
        return !empty($this->config->alternateserver_hostname) &&
                !empty($this->config->alternateindexname) &&
                !empty($this->config->alternateserver_port);
    }
}
