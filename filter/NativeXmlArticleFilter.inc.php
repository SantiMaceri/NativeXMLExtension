<?php

/**
 * @file plugins/importexport/native/filter/NativeXmlArticleFilter.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlArticleFilter
 * @ingroup plugins_importexport_native
 *
 * @brief Class that converts a Native XML document to a set of articles.
 */

import('lib.pkp.plugins.importexport.native.filter.NativeXmlSubmissionFilter');

class NativeXmlArticleFilter extends NativeXmlSubmissionFilter {
	/**
	 * Constructor
	 * @param $filterGroup FilterGroup
	 */
	function __construct($filterGroup) {
		parent::__construct($filterGroup);
	}


	//
	// Implement template methods from PersistableFilter
	//
	/**
	 * @copydoc PersistableFilter::getClassName()
	 */
	function getClassName() {
		return 'plugins.importexport.native.filter.NativeXmlArticleFilter';
	}

	/**
	 * Get the method name for inserting a published submission.
	 * @return string
	 */
	function getPublishedSubmissionInsertMethod() {
		return 'insertObject';
	}

	/**
	 * @see Filter::process()
	 * @param $document DOMDocument|string
	 * @return array Array of imported documents
	 */
	function &process(&$document) {
		$importedObjects =& parent::process($document);

		$deployment = $this->getDeployment();
		$submission = $deployment->getSubmission();
		
		// Index imported content
		$articleSearchIndex = Application::getSubmissionSearchIndex();
		foreach ($importedObjects as $submission) {
			assert(is_a($submission, 'Submission'));
			$articleSearchIndex->submissionMetadataChanged($submission);
			$articleSearchIndex->submissionFilesChanged($submission);
		}

		$articleSearchIndex->submissionChangesFinished();

		return $importedObjects;
	}

	/**
	 * Populate the submission object from the node, checking first for a valid section and published_date/issue relationship
	 * @param $submission Submission
	 * @param $node DOMElement
	 * @return Submission
	 */
	function populateObject($submission, $node) {
		return parent::populateObject($submission, $node);
	}

	/**
	 * Handle an element whose parent is the submission element.
	 * @param $n DOMElement
	 * @param $submission Submission
	 */
	function handleChildElement($n, $submission) {
		switch ($n->tagName) {
			case 'artwork_file':
			case 'supplementary_file':
				$this->parseSubmissionFile($n, $submission);
			break;
			case 'participants':
				$this->parseParticipants($n, $submission);
				
			break;
			case 'stages':
				$this->parseStages($n, $submission);
			break;
			default:
				parent::handleChildElement($n, $submission);
		}
	}

	/**
	 * Get the import filter for a given element.
	 * @param $elementName string Name of XML element
	 * @return Filter
	 */
	function getImportFilter($elementName) {
		$deployment = $this->getDeployment();
		$submission = $deployment->getSubmission();
		switch ($elementName) {
			case 'submission_file':
				$importClass='SubmissionFile';
				break;
			case 'artwork_file':
				$importClass='SubmissionArtworkFile';
				break;
			case 'supplementary_file':
				$importClass='SupplementaryFile';
				break;
			case 'publication':
				$importClass='Publication';
				break;
			default:
				$importClass=null; // Suppress scrutinizer warn
				$deployment->addWarning(ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.common.error.unknownElement', array('param' => $elementName)));
		}
		// Caps on class name for consistency with imports, whose filter
		// group names are generated implicitly.
		$filterDao = DAORegistry::getDAO('FilterDAO'); /* @var $filterDao FilterDAO */
		$importFilters = $filterDao->getObjectsByGroup('native-xml=>' . $importClass);
		$importFilter = array_shift($importFilters);
		return $importFilter;
	}


	//////////////////////////////////////////////////

	/**
	 * Parsing of participants
	 * @param $n DOMElement
	 * @param $submission Submission
	 */
	function parseParticipants($n, $submission){
		$deployment = $this->getDeployment();
		$context = $deployment->getContext();

		$userDao = DAORegistry::getDAO('UserDAO');
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');

		$participants = $n->getElementsByTagName("participant");
		foreach ($participants as $p){
			$user = $userDao->getUserByEmail($p->getAttribute("mail"));		
			$userGroupName = $p->getAttribute('user_group_ref');
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /** @var $userGroupDao UserGroupDAO */
			$userGroups = $userGroupDao->getByContextId($context->getId());
			while ($userGroup = $userGroups->next()) {
				if (in_array($userGroupName, $userGroup->getName(null))) {
					// Found a candidate; stash it.
					$ug = $userGroup;
					break;
				}
			}
			//Parece el indicado para crear stageAssigments
			$stageAssignment = $stageAssignmentDao->build($submission->getId(), $ug->getId(), $user->getId());

		}
	}

	function parseStages($n, $submission){
		$deployment = $this->getDeployment();
		$context = $deployment->getContext();
	
		$stages = $n->getElementsByTagName("stage");

		foreach($stages as $stage){
			$queries = $stage->getElementsByTagName("queries")[0]->getElementsByTagName("query");
			$this->parseQueries($queries, $submission, $stage->getAttribute("id") );
			if($stage->getAttribute("id") == WORKFLOW_STAGE_ID_EXTERNAL_REVIEW ){
				$rounds = $stage->getElementsByTagName("rounds")[0]->getElementsByTagName("round");
				$this->parseRounds($rounds,$submission, $stage->getAttribute("id"));
			}
		}
		
		
	}

	function parseQueries($queries, $submission, $stageId){

		$queryDao = DAORegistry::getDAO('QueryDAO'); 
		$noteDao = DAORegistry::getDAO('NoteDAO'); 
		$userDao = DAORegistry::getDAO('UserDAO');
	
		foreach($queries as $query){
			//Create a query
			$queryObj = $queryDao->newDataObject();
			$queryObj->setAssocType(ASSOC_TYPE_SUBMISSION);
			$queryObj->setAssocId($submission->getId());
			$queryObj->setStageId($stageId);
			$queryObj->setSequence(REALLY_BIG_NUMBER);
			$queryDao->insertObject($queryObj);
			$queryDao->resequence(ASSOC_TYPE_SUBMISSION, $submission->getId());

			$notes = $query->getElementsByTagName("note");

			foreach($notes as $note){

				//Insert participants
				if (!in_array($userDao->getUserByEmail($note->getAttribute("user"))->getId(), $queryDao->getParticipantIds($queryObj->getId()))) {
					$queryDao->insertParticipant($queryObj->getId(),$userDao->getUserByEmail($note->getAttribute("user"))->getId());
				}
				//Create note
				$noteObj = $noteDao->newDataObject();
				$noteObj->setAssocType(ASSOC_TYPE_QUERY);
				$noteObj->setAssocId($queryObj->getId());
				$noteObj->setUserId($userDao->getUserByEmail($note->getAttribute("user"))->getId());
				$noteObj->setDateCreated(Core::getCurrentDate());
				$noteObj->setContents($note->textContent);
				if($note->getAttribute("title")){
					$noteObj->setTitle($note->getAttribute("title"));
				}
				$noteDao->insertObject($noteObj);

			}
		}
	
	}


	function parseRounds($rounds,$submission, $stageId){
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
		// Bring in the SUBMISSION_FILE_* constants.
		import('lib.pkp.classes.submission.SubmissionFile');
		$submissionFiles = $submissionFileDao->getBySubmissionId($submission->getId());

		$reviewFiles = array_filter($submissionFiles, 
									function($file){ 
										return($file->getFileStage() == SUBMISSION_FILE_REVIEW_FILE);
									}
						);
		$revisionFiles = array_filter($submissionFiles, 
									function($file){ 
										return($file->getFileStage() == SUBMISSION_FILE_REVIEW_REVISION);
									}
						);				
		foreach($rounds as $round){
			$this->parseRound($round, $submission, $stageId, $reviewFiles, $revisionFiles);
		}
	}

	function parseRound($round , $submission, $stageId, &$reviewFiles, &$revisionFiles){
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
		$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /* @var $reviewRoundDao ReviewRoundDAO */
		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
		$userDao = DAORegistry::getDAO('UserDAO');
		$reviewFormDao = DAORegistry::getDAO('ReviewFormDAO');
		$submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /* @var $submissionCommentDao SubmissionCommentDAO */


		$context = $this->getDeployment()->getContext();



		$lastReviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), $stageId);
		if ($lastReviewRound) {
			$newRound = $lastReviewRound->getRound() + 1;
		} else {
			// If we don't have any review round, we create the first one.
			$newRound = 1;
		}
		
		// Create a new review round.
		$reviewRound = $reviewRoundDao->build($submission->getId(), $stageId, $newRound, null);		
		
		$files = $round->getElementsByTagName("file");

		/* La obtencion de los files a traves del DAO viene en orden, por lo tanto,
		   si los files de los rounds estan en orden yo puede determinar en que round esta cada file.
		*/

		foreach($files as $file){ //DEBERIA LLAMARSE FILENODE
			
			if($file->getAttribute("stage") == SUBMISSION_FILE_REVIEW_FILE){
				if(count($reviewFiles) > 0){
					$reviewFile = array_shift($reviewFiles);
					$submissionFileDao->assignRevisionToReviewRound($reviewFile->getId(), $reviewFile->getRevision(), $reviewRound);
				}
			}
		
			if($file->getAttribute("stage") == SUBMISSION_FILE_REVIEW_REVISION){
				if(count($revisionFiles) > 0){
					$revisionFile = array_shift($revisionFiles);
					$submissionFileDao->assignRevisionToReviewRound($revisionFile->getId(), $revisionFile->getRevision(), $reviewRound);
				}
			}
		}

		$reviewAssignments = $round->getElementsByTagName("reviewAssignment");
		
		foreach($reviewAssignments as $reviewAssignmentNode){
			$reviewAssignment = $reviewAssignmentDao->newDataObject();
			$reviewAssignment->setSubmissionId($submission->getId());
			$reviewAssignment->setReviewerId($userDao->getUserByEmail($reviewAssignmentNode->getAttribute("reviewer"))->getId());
			$reviewAssignment->setDateAssigned($reviewAssignmentNode->getAttribute("date_assigned"));
			$reviewAssignment->setStageId($stageId);
			$reviewAssignment->setRound($newRound);
			$reviewAssignment->setReviewRoundId($reviewRound->getId());
			$reviewAssignment->setReviewMethod($reviewAssignmentNode->getAttribute("method"));
			$reviewAssignment->setUnconsidered($reviewAssignmentNode->getAttribute("unconsidered"));
			$reviewAssignment->setDateRated($reviewAssignmentNode->getAttribute("date_rated"));
			$reviewAssignment->setLastModified($reviewAssignmentNode->getAttribute("last_modified"));
			if($reviewAssignmentNode->getAttribute("date_assigned") != "1970-01-01 00:00"){
				$reviewAssignment->setDateAssigned($reviewAssignmentNode->getAttribute("date_assigned"));
			}
			if($reviewAssignmentNode->getAttribute("date_notified") != "1970-01-01 00:00"){
				$reviewAssignment->setDateNotified($reviewAssignmentNode->getAttribute("date_notified"));
			}
			if($reviewAssignmentNode->getAttribute("date_confirmed") != "1970-01-01 00:00"){
				$reviewAssignment->setDateConfirmed($reviewAssignmentNode->getAttribute("date_confirmed"));
			}
			if($reviewAssignmentNode->getAttribute("date_completed") != "1970-01-01 00:00"){
				$reviewAssignment->setDateCompleted($reviewAssignmentNode->getAttribute("date_completed"));
			}
			if($reviewAssignmentNode->getAttribute("date_acknowledged") != "1970-01-01 00:00"){
				$reviewAssignment->setDateAcknowledged($reviewAssignmentNode->getAttribute("date_acknowledged"));
			}
			if($reviewAssignmentNode->getAttribute("date_reminded") != "1970-01-01 00:00"){
				$reviewAssignment->setDateReminded($reviewAssignmentNode->getAttribute("date_reminded"));
			}
			$reviewAssignment->setDateDue($reviewAssignmentNode->getAttribute("date_due"));
			$reviewAssignment->setDateResponseDue($reviewAssignmentNode->getAttribute("date_response_due"));
			$reviewAssignment->setDeclined($reviewAssignmentNode->getAttribute("declined"));
			$reviewAssignment->setCancelled($reviewAssignmentNode->getAttribute("cancelled"));
			$reviewAssignment->setReminderWasAutomatic($reviewAssignmentNode->getAttribute("automatic"));
			if($reviewAssignmentNode->getAttribute("quality") != ""){
				$reviewAssignment->setQuality($reviewAssignmentNode->getAttribute("quality")); 
			}
			else{
				$reviewAssignment->setQuality(0); 
			}
			
			
			if($reviewAssignmentNode->getAttribute("recommendation") != ""){
				$reviewAssignment->setRecommendation($reviewAssignmentNode->getAttribute("recommendation"));
			}
			$reviewAssignment->setCompetingInterests($reviewAssignmentNode->getAttribute("competing_interest"));
			
			
			$reviewAssignmentDao->insertObject($reviewAssignment);

			$formNode = $reviewAssignmentNode->getElementsByTagName("form")[0];
			$reviewForms = $reviewFormDao->getByAssocId(Application::getContextAssocType(), $context->getId())->toArray();

			$selectedForm = array_filter($reviewForms, 
										function($form) use($formNode){ 
											return($form->getLocalizedTitle() == $formNode->getAttribute("title") );
										}
							);

			//exit(json_encode($selectedForm));
			if($formNode->getAttribute("title") == "default"){
				$reviewAssignment->setReviewFormId(0);
				
			$answers = $formNode->getElementsByTagName("answer");
			foreach($answers as $answersNode){
				$comment = $submissionCommentDao->newDataObject();
				$comment->setCommentType(COMMENT_TYPE_PEER_REVIEW);
				$comment->setRoleId(ROLE_ID_REVIEWER);
				$comment->setAssocId($reviewAssignment->getId());
				$comment->setSubmissionId($reviewAssignment->getSubmissionId());
				$comment->setAuthorId($userDao->getUserByEmail($reviewAssignmentNode->getAttribute("reviewer"))->getId());
				$comment->setComments($answersNode->getAttribute("value"));
				$comment->setCommentTitle('');
				if($answersNode->getAttribute("viewable") == "true"){
					$comment->setViewable(true);
				}
				else{
					$comment->setViewable(false);
				}
				
				$comment->setDatePosted(Core::getCurrentDate());
				$submissionCommentDao->insertObject($comment);

			}
				

			}else{
				//exit($formNode->getElementsByTagName("answer")[0]->getAttribute("value"));
				$reviewAssignment->setReviewFormId($selectedForm[1]->getId());

			}

		}

	}

	
}
