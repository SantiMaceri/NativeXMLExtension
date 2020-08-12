<?php

/**
 * @file plugins/importexport/native/filter/ArticleNativeXmlFilter.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleNativeXmlFilter
 * @ingroup plugins_importexport_native
 *
 * @brief Class that converts a Article to a Native XML document.
 */

import('lib.pkp.plugins.importexport.native.filter.SubmissionNativeXmlFilter');

class ArticleNativeXmlFilter extends SubmissionNativeXmlFilter {
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
		return 'plugins.importexport.native.filter.ArticleNativeXmlFilter';
	}

	//
	// Submission conversion functions
	//
	/**
	 * Create and return a submission node.
	 * @param $doc DOMDocument
	 * @param $submission Submission
	 * @return DOMElement
	 */
	function createSubmissionNode($doc, $submission) {

		$deployment = $this->getDeployment();
		$submissionNode = parent::createSubmissionNode($doc, $submission);
		$submissionNode->appendChild($this->createParticipantsNode($doc, $deployment, $submission));
		$submissionNode->appendChild($this->createStagesNode($doc,$deployment, $submission));
		return $submissionNode;
	}	



	/**
	 * Helper method to obtain participants from a submission
	 * @param $submission Submission
	 * @return Array of users
	 */

	function getStageAssigmentsFromSubmission($submission){
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /* @var $stageAssignmentDao StageAssignmentDAO */
		$stageAssignments = $stageAssignmentDao->getBySubmissionAndStageId(
			$submission->getId(),
			$submission->getStageId()
		);
		return $stageAssignments->toArray();
	}

	function createParticipantsNode($doc, $deployment, $submission){
	
		$participantsNode = $doc->createElementNS($deployment->getNamespace(), 'participants');

		foreach ($this->getStageAssigmentsFromSubmission($submission) as $stageAssignment){
			$participantsNode->appendChild($this->createParticipantNode($doc, $deployment, $submission, $stageAssignment));
		}
		return $participantsNode;
	}

	function createParticipantNode($doc, $deployment, $submission, $stageAssignment){
		$userDao = DAORegistry::getDAO('UserDAO');
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

		$participantNode = $doc->createElementNS($deployment->getNamespace(), 'participant');
		$participantNode->setAttribute("mail", $userDao->getById($stageAssignment->getUserId())->getEmail() ); 
		$participantNode->setAttribute("user_group_ref", $userGroupDao->getById($stageAssignment->getUserGroupId())->getLocalizedName());

		return $participantNode;
	}


	function createQueriesNode($doc, $deployment, $submission, $stageId){
		$userDao = DAORegistry::getDAO('UserDAO');


		$queriesNode = $doc->createElementNS($deployment->getNamespace(), 'queries');
		foreach($this->getQueriesFromSubmission($submission, $stageId) as $query){
			$queryNode = $doc->createElementNS($deployment->getNamespace(),'query');
			foreach($query->getReplies()->toArray() as $note){
				$noteNode = $doc->createElementNS($deployment->getNamespace(),'note', $note->getContents());
				$noteNode->setAttribute('user', $userDao->getById($note->getUserId())->getEmail());
				$noteNode->setAttribute('date_created', $note->getDateCreated());
				$noteNode->setAttribute('date_modified', $note->getDateModified());
				if($note->getTitle()){
					$noteNode->setAttribute('title', $note->getTitle());
				}
				$queryNode->appendChild($noteNode);
			}
			$queriesNode->appendChild($queryNode);
		}
		return $queriesNode;
	}

	function getQueriesFromSubmission($submission, $stageId){
		$queryDao = DAORegistry::getDAO('QueryDAO');
		$queries = $queryDao->getByAssoc(ASSOC_TYPE_SUBMISSION, $submission->getId(), $stageId)->toArray();
		return $queries;
	}


	function createStagesNode($doc, $deployment, $submission){
		$stagesNode = $doc->createElementNS($deployment->getNamespace(), 'stages');
		$stages= array(
			WORKFLOW_STAGE_ID_SUBMISSION => 'submission',
			WORKFLOW_STAGE_ID_INTERNAL_REVIEW => 'internalReview',
			WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => 'externalReview',
			WORKFLOW_STAGE_ID_EDITING => 'copyEditing',
			WORKFLOW_STAGE_ID_PRODUCTION => 'production'
		);
		foreach($stages as $stageId => $value){
			$stageNode = $doc->createElementNS($deployment->getNamespace(), 'stage');
			$stageNode->setAttribute('id', $stageId);
			$stageNode->setAttribute('name', $value);

			$stageNode->appendChild($this->createQueriesNode($doc, $deployment, $submission, $stageId));
			

			if($stageId == WORKFLOW_STAGE_ID_EXTERNAL_REVIEW){
				$stageNode->appendChild($this->createRoundsNode($doc, $deployment, $submission));
			}

			$stagesNode->appendChild($stageNode);
		}
	
		return $stagesNode;

	}


	function createRoundsNode($doc, $deployment, $submission){
		$reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');

		$roundsNode = $doc->createElementNS($deployment->getNamespace(), 'rounds');

		$rounds = $reviewRoundDao->getBySubmissionId($submission->getId(), WORKFLOW_STAGE_ID_EXTERNAL_REVIEW)->toArray();
		foreach($rounds as $round){
			$roundsNode->appendChild($this->createRoundNode($doc, $deployment, $submission, $round));
		}

		return $roundsNode;


	}


	function createRoundNode($doc, $deployment, $submission, $round){
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
		$roundNode = $doc->createElementNS($deployment->getNamespace(), 'round');
		
		$reviewAssignments = $reviewAssignmentDao->getByReviewRoundId($round->getId());
		foreach($reviewAssignments as $reviewAssignment){
			$roundNode->appendChild($this->createReviewAssignmentNode($doc, $deployment, $submission, $reviewAssignment));
		}

		$files = $submissionFileDao->getRevisionsByReviewRound($round);
		foreach($files as $submissionFile){
			$roundNode->appendChild($this->createFileNode($doc,$deployment,$submission, $submissionFile));
		}
			
		return $roundNode;
	}

	function createReviewAssignmentNode($doc, $deployment, $submission, $reviewAssignment){
		$userDao = DAORegistry::getDAO('UserDAO');
		$reviewFormDao = DAORegistry::getDAO('ReviewFormDAO');


		$reviewAssignmentNode= $doc->createElementNS($deployment->getNamespace(), 'reviewAssignment');
		$reviewAssignmentNode->setAttribute("reviewer", $userDao->getById($reviewAssignment->getReviewerId())->getEmail() );
		$reviewAssignmentNode->setAttribute("method", $reviewAssignment->getReviewMethod());
		$reviewAssignmentNode->setAttribute("round", $reviewAssignment->getRound());
		$reviewAssignmentNode->setAttribute("unconsidered", $reviewAssignment->getUnconsidered());
		$reviewAssignmentNode->setAttribute("date_rated", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateRated())));
		$reviewAssignmentNode->setAttribute("last_modified", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getLastModified())));
		$reviewAssignmentNode->setAttribute("date_assigned", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateAssigned())));
		$reviewAssignmentNode->setAttribute("date_notified", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateNotified())));
		$reviewAssignmentNode->setAttribute("date_confirmed", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateConfirmed())));
		$reviewAssignmentNode->setAttribute("date_completed", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateCompleted())));
		$reviewAssignmentNode->setAttribute("date_acknowledged", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateAcknowledged())));
		$reviewAssignmentNode->setAttribute("date_reminded", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateReminded())));
		$reviewAssignmentNode->setAttribute("date_due", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateDue())));
		$reviewAssignmentNode->setAttribute("date_response_due", strftime('%Y-%m-%d %H:%M', strtotime($reviewAssignment->getDateResponseDue())));
		$reviewAssignmentNode->setAttribute("declined", $reviewAssignment->getDeclined());
		$reviewAssignmentNode->setAttribute("cancelled", $reviewAssignment->getCancelled());
		$reviewAssignmentNode->setAttribute("automatic", $reviewAssignment->getReminderWasAutomatic());
		$reviewAssignmentNode->setAttribute("quality", $reviewAssignment->getQuality());
		$reviewAssignmentNode->setAttribute("form", $reviewAssignment->getReviewFormId()); //SEGURAMENTE CAMBIAR
		$reviewAssignmentNode->setAttribute("recommendation", $reviewAssignment->getRecommendation()); 
		$reviewAssignmentNode->setAttribute("competing_interest", $reviewAssignment->getCompetingInterests()); 



		//POsible forma de obtener el form, esta tirando error porque no hay ninguno definido.
		$reviewForm = $reviewFormDao->getById($reviewAssignment->getReviewFormId(), Application::getContextAssocType(), $deployment->getContext()->getId());


		//Asi se obtienen los comments de las reviewAssigments, puede que haya que crear otro nodo comment
		// $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /* @var $submissionCommentDao SubmissionCommentDAO */
		// $c = $submissionCommentDao->getReviewerCommentsByReviewerId($reviewAssignment->getSubmissionId(), null, $reviewAssignment->getId(), true);
		// $d = $submissionCommentDao->getReviewerCommentsByReviewerId($reviewAssignment->getSubmissionId(), null, $reviewAssignment->getId(), false);


		

		return $reviewAssignmentNode;

	}

	function createFileNode($doc, $deployment, $submission, $submissionFile){
		$fileNode= $doc->createElementNS($deployment->getNamespace(), 'file');
		$fileNode->setAttribute("id", $submissionFile->getId());
		$fileNode->setAttribute('number', $submissionFile->getRevision());
		$fileNode->setAttribute('stage', $submissionFile->getFileStage());
		if ($sourceFileId = $submissionFile->getSourceFileId()) {
			$fileNode->setAttribute('source', $sourceFileId . '-' . $submissionFile->getSourceRevision());
		}

		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		$genre = $genreDao->getById($submissionFile->getGenreId());
		if ($genre) {
			$fileNode->setAttribute('genre', $genre->getName($deployment->getContext()->getPrimaryLocale()));
		}
		$fileNode->setAttribute('filename', $submissionFile->getOriginalFileName());
		$fileNode->setAttribute('viewable', $submissionFile->getViewable()?'true':'false');
		$fileNode->setAttribute('date_uploaded', strftime('%Y-%m-%d', strtotime($submissionFile->getDateUploaded())));
		$fileNode->setAttribute('date_modified', strftime('%Y-%m-%d', strtotime($submissionFile->getDateModified())));
		if ($submissionFile->getDirectSalesPrice() !== null) {
			$fileNode->setAttribute('direct_sales_price', $submissionFile->getDirectSalesPrice());
		}
		$fileNode->setAttribute('filesize', $submissionFile->getFileSize());
		$fileNode->setAttribute('filetype', $submissionFile->getFileType());

		$userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
		$uploaderUser = $userDao->getById($submissionFile->getUploaderUserId());
		assert(isset($uploaderUser));
		$fileNode->setAttribute('uploader', $uploaderUser->getEmail());

		$this->createLocalizedNodes($doc, $fileNode, 'name', $submissionFile->getName(null));

		// $embedNode = $doc->createElementNS($deployment->getNamespace(), 'embed', base64_encode(file_get_contents($submissionFile->getFilePath())));
		// $embedNode->setAttribute('encoding', 'base64');
		// $fileNode->appendChild($embedNode);
		return $fileNode;
	}

}
