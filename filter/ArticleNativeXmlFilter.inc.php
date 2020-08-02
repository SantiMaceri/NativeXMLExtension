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

			$stagesNode->appendChild($stageNode);
		}
	
		return $stagesNode;

	}


}
