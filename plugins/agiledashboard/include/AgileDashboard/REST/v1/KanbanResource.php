<?php
/**
 * Copyright (c) Enalean, 2014-2015. All Rights Reserved.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

namespace Tuleap\AgileDashboard\REST\v1;

use Luracast\Restler\RestException;
use Tuleap\REST\Header;
use AgileDashboard_KanbanFactory;
use AgileDashboard_KanbanDao;
use AgileDashboard_KanbanItemDao;
use AgileDashboard_KanbanNotFoundException;
use AgileDashboard_KanbanCannotAccessException;
use AgileDashboard_Kanban;
use AgileDashboard_KanbanColumnFactory;
use AgileDashboard_KanbanColumnDao;
use UserManager;
use Exception;
use TrackerFactory;
use PFUser;
use Tracker_Artifact_PriorityHistoryChange;
use Tracker_Artifact_PriorityDao;
use Tracker_Artifact_PriorityHistoryDao;
use Tracker_ArtifactFactory;
use Tracker_Artifact_PriorityManager;
use Tracker_NoChangeException;
use Tracker_FormElement_Field_List_Bind;
use Tracker_Semantic_Status;

class KanbanResource {

    const MAX_LIMIT = 100;

    /** @var AgileDashboard_KanbanFactory */
    private $kanban_factory;

    /** @var ResourcesPatcher */
    private $resources_patcher;

    /** @var AgileDashboard_KanbanItemDao */
    private $kanban_item_dao;

    /** @var TrackerFactory */
    private $tracker_factory;

    /** @var Tracker_ArtifactFactory */
    private $artifact_factory;

    /** @var AgileDashboard_KankanColumnFactory */
    private $kanban_column_factory;

    public function __construct() {
        $this->kanban_item_dao = new AgileDashboard_KanbanItemDao();
        $this->tracker_factory = TrackerFactory::instance();

        $this->kanban_factory = new AgileDashboard_KanbanFactory(
            $this->tracker_factory,
            new AgileDashboard_KanbanDao()
        );

        $this->kanban_column_factory = new AgileDashboard_KanbanColumnFactory(new AgileDashboard_KanbanColumnDao());

        $this->artifact_factory = Tracker_ArtifactFactory::instance();
        $priority_manager       = new Tracker_Artifact_PriorityManager(
            new Tracker_Artifact_PriorityDao(),
            new Tracker_Artifact_PriorityHistoryDao(),
            UserManager::instance(),
            $this->artifact_factory
        );
        $artifactlink_updater    = new ArtifactLinkUpdater($priority_manager);

        $this->resources_patcher = new ResourcesPatcher(
            $artifactlink_updater,
            $this->artifact_factory,
            $priority_manager
        );
    }

    /**
     * @url OPTIONS
     */
    public function options() {
        Header::allowOptions();
    }

    /**
     * Get kanban
     *
     * Get the definition of a given kanban
     *
     * @url GET {id}
     *
     * @param int $id Id of the kanban
     *
     * @return Tuleap\AgileDashboard\REST\v1\KanbanRepresentation
     *
     * @throws 403
     * @throws 404
     */
    protected function getId($id) {
        $user   = $this->getCurrentUser();
        $kanban = $this->getKanban($user, $id);

        $kanban_representation = new KanbanRepresentation();
        $kanban_representation->build($kanban, $this->kanban_column_factory);

        Header::allowOptionsGet();
        return $kanban_representation;
    }

    /**
     * Return info about milestone if exists
     *
     * @url OPTIONS {id}
     *
     * @param string $id Id of the milestone
     */
    public function optionsId($id) {
        Header::allowOptionsGet();
    }

    /**
     * Get backlog
     *
     * Get the backlog of a given kanban
     *
     * @url GET {id}/backlog
     *
     * @param int $id Id of the kanban
     * @param int $limit  Number of elements displayed per page
     * @param int $offset Position of the first element to display
     *
     * @return Tuleap\AgileDashboard\REST\v1\KanbanBacklogRepresentation
     *
     * @throws 403
     * @throws 404
     */
    protected function getBacklog($id, $limit = 10, $offset = 0) {
        $user   = $this->getCurrentUser();
        $kanban = $this->getKanban($user, $id);

        $backlog_representation = new KanbanBacklogRepresentation();
        $backlog_representation->build($user, $kanban, $limit, $offset);

        Header::allowOptionsGet();
        Header::sendPaginationHeaders($limit, $offset, $backlog_representation->total_size, self::MAX_LIMIT);

        return $backlog_representation;
    }

    /**
     * Partial re-order of Kanban backlog items
     *
     * @url PATCH {id}/backlog
     *
     * @param int                                                    $id    Id of the Kanban
     * @param \Tuleap\AgileDashboard\REST\v1\OrderRepresentation     $order Order of the children {@from body}
     * @param \Tuleap\AgileDashboard\REST\v1\KanbanAddRepresentation $add   Ids to add to Kanban backlog {@from body}
     *
     */
    protected function patchBacklog($id, OrderRepresentation $order = null, KanbanAddRepresentation $add = null) {
        $current_user = UserManager::instance()->getCurrentUser();
        $kanban       = $this->kanban_factory->getKanban($current_user, $id);

        if ($add) {
            $add->checkFormat();
            $this->validateIdsInAddAreInKanbanTracker($kanban, $add);
            $this->resources_patcher->startTransaction();

            try {
                $this->moveArtifactsInBacklog($kanban, $current_user, $add);
                $this->resources_patcher->commit();
            } catch (Tracker_NoChangeException $exception) {
                $this->resources_patcher->rollback();
            } catch (Exception $exception) {
                $this->resources_patcher->rollback();
                throw new RestException(500, $exception->getMessage());
            }
        }

        if ($order) {
            $order->checkFormat();
            $kanban_backlog_items = $this->getKanbanBacklogItemIds($kanban->getTrackerId());
            $order_validator      = new OrderValidator($kanban_backlog_items);

            try {
                $order_validator->validate($order);
            } catch (IdsFromBodyAreNotUniqueException $exception) {
                throw new RestException(409, $exception->getMessage());
            } catch (OrderIdOutOfBoundException $exception) {
                throw new RestException(409, $exception->getMessage());
            } catch (Exception $exception) {
                throw new RestException(500, $exception->getMessage());
            }

            $this->resources_patcher->updateArtifactPriorities(
                $order,
                Tracker_Artifact_PriorityHistoryChange::NO_CONTEXT,
                $this->tracker_factory->getTrackerById($kanban->getTrackerId())->getGroupId()
            );
        }
    }

    private function getStatusField(AgileDashboard_Kanban $kanban, PFUser $user) {
        $tracker      = $this->tracker_factory->getTrackerById($kanban->getTrackerId());
        $status_field = $tracker->getStatusField();

        if (! $status_field) {
            throw new RestException(403);
        }

        if (! $status_field->userCanRead($user)) {
            throw new RestException(403);
        }

        return $status_field;
    }

    private function moveArtifactsInBacklog(AgileDashboard_Kanban $kanban, PFUser $user, KanbanAddRepresentation $add) {
        $this->moveArtifactsInColumn($kanban, $user, $add, Tracker_FormElement_Field_List_Bind::NONE_VALUE);
    }

    private function moveArtifactsInArchive(AgileDashboard_Kanban $kanban, PFUser $user, KanbanAddRepresentation $add) {
        $a_closed_value_id = null;
        $status_field      = $this->getStatusField($kanban, $user);
        $open_values       = $this->getSemanticStatus($kanban)->getOpenValues();
        foreach ($status_field->getAllValues() as $value_id => $value) {
            if (! $value->isHidden() && ! in_array($value_id, $open_values)) {
                $a_closed_value_id = $value_id;
                break;
            }
        }

        if (! $a_closed_value_id) {
            throw new RestException(500, 'Cannot found any suitable value corresponding to the [archive] column.');
        }
        $this->moveArtifactsInColumn($kanban, $user, $add, $a_closed_value_id);
    }

    private function moveArtifactsInColumn(
        AgileDashboard_Kanban $kanban,
        PFUser $user,
        KanbanAddRepresentation $add,
        $column_id
    ) {
        foreach ($add->ids as $artifact_id) {
            $artifact        = $this->artifact_factory->getArtifactById($artifact_id);
            $status_field    = $this->getStatusField($kanban, $user);

            $fields_data = array(
                $status_field->getId() => $column_id
            );

            $artifact->createNewChangeset($fields_data, '', $user);
        }
    }

    private function validateIdsInAddAreInKanbanTracker(AgileDashboard_Kanban $kanban, KanbanAddRepresentation $add) {
        $all_kanban_item_ids = array();
        foreach ($this->kanban_item_dao->getAllKanbanItemIds($kanban->getTrackerId()) as $item_id) {
            $all_kanban_item_ids[] = $item_id;
        }

        return count(array_diff($add->ids, $all_kanban_item_ids)) === 0;
    }

    private function getKanbanBacklogItemIds($tracker_id) {
        $backlog_item_ids = array();
        foreach ($this->kanban_item_dao->getKanbanBacklogItemIds($tracker_id) as $artifact) {
            $backlog_item_ids[$artifact['id']] = true;
        }

        return $backlog_item_ids;
    }
    /**
     * @url OPTIONS {id}/backlog
     *
     * @param string $id Id of the milestone
     */
    public function optionsBacklog($id) {
        Header::allowOptionsGetPatch();
    }

    /**
     * Get archive
     *
     * Get the archived items of a given kanban
     *
     * @url GET {id}/archive
     *
     * @param int $id Id of the kanban
     * @param int $limit  Number of elements displayed per page
     * @param int $offset Position of the first element to display
     *
     * @return Tuleap\AgileDashboard\REST\v1\KanbanArchiveRepresentation
     *
     * @throws 403
     * @throws 404
     */
    protected function getArchive($id, $limit = 10, $offset = 0) {
        $user   = $this->getCurrentUser();
        $kanban = $this->getKanban($user, $id);

        $items_representation = new KanbanArchiveRepresentation();
        $items_representation->build($user, $kanban, $limit, $offset);

        Header::allowOptionsGet();
        Header::sendPaginationHeaders($limit, $offset, $items_representation->total_size, self::MAX_LIMIT);

        return $items_representation;
    }

    /**
     * Partial re-order of Kanban archive items
     *
     * @url PATCH {id}/archive
     *
     * @param int                                                    $id    Id of the Kanban
     * @param \Tuleap\AgileDashboard\REST\v1\OrderRepresentation     $order Order of the children {@from body}
     * @param \Tuleap\AgileDashboard\REST\v1\KanbanAddRepresentation $add   Ids to add to Kanban backlog {@from body}
     */
    protected function patchArchive($id, OrderRepresentation $order = null, KanbanAddRepresentation $add = null) {
        $current_user = UserManager::instance()->getCurrentUser();
        $kanban       = $this->kanban_factory->getKanban($current_user, $id);

        if ($add) {
            $add->checkFormat();
            $this->validateIdsInAddAreInKanbanTracker($kanban, $add);
            $this->resources_patcher->startTransaction();

            try {
                $this->moveArtifactsInArchive($kanban, $current_user, $add);
                $this->resources_patcher->commit();
            } catch (Tracker_NoChangeException $exception) {
                $this->resources_patcher->rollback();
            } catch (Exception $exception) {
                $this->resources_patcher->rollback();
                throw new RestException(500, $exception->getMessage());
            }
        }


        if ($order) {
            $order->checkFormat();
            $kanban_archive_items = $this->getKanbanArchiveItemIds($kanban->getTrackerId());
            $order_validator      = new OrderValidator($kanban_archive_items);

            try {
                $order_validator->validate($order);
            } catch (IdsFromBodyAreNotUniqueException $exception) {
                throw new RestException(409, $exception->getMessage());
            } catch (OrderIdOutOfBoundException $exception) {
                throw new RestException(409, $exception->getMessage());
            } catch (Exception $exception) {
                throw new RestException(500, $exception->getMessage());
            }

            $this->resources_patcher->updateArtifactPriorities(
                $order,
                Tracker_Artifact_PriorityHistoryChange::NO_CONTEXT,
                $this->tracker_factory->getTrackerById($kanban->getTrackerId())->getGroupId()
            );
        }
    }

    private function getKanbanArchiveItemIds($tracker_id) {
        $archive_item_ids = array();
        foreach ($this->kanban_item_dao->getKanbanArchiveItemIds($tracker_id) as $artifact) {
            $archive_item_ids[$artifact['id']] = true;
        }

        return $archive_item_ids;
    }

    /**
     * @url OPTIONS {id}/archive
     *
     * @param string $id Id of the milestone
     */
    public function optionsArchive($id) {
        Header::allowOptionsGetPatch();
    }

    /**
     * Get items
     *
     * Get the items of a given kanban in a given column
     *
     * @url GET {id}/items
     *
     * @param int $id Id of the kanban
     * @param int $column_id Id of the column the item belongs to
     * @param int $limit  Number of elements displayed per page
     * @param int $offset Position of the first element to display
     *
     * @return Tuleap\AgileDashboard\REST\v1\KanbanItemCollectionRepresentation
     *
     * @throws 403
     * @throws 404
     */
    protected function getItems($id, $column_id, $limit = 10, $offset = 0) {
        $user   = $this->getCurrentUser();
        $kanban = $this->getKanban($user, $id);

        if (! $this->columnIsInTracker($kanban, $user, $column_id)) {
            throw new RestException(404);
        }

        $items_representation = new KanbanItemCollectionRepresentation();
        $items_representation->build($user, $kanban, $column_id, $limit, $offset);

        Header::allowOptionsGet();
        Header::sendPaginationHeaders($limit, $offset, $items_representation->total_size, self::MAX_LIMIT);

        return $items_representation;
    }

    private function columnIsInTracker(AgileDashboard_Kanban $kanban, PFUser $user, $column_id) {
        $status_field = $this->getStatusField($kanban, $user);

        return array_key_exists($column_id, $status_field->getAllValues());
    }

    /**
     * Partial re-order of Kanban items
     *
     * @url PATCH {id}/items
     *
     * @param int                                                    $id    Id of the Kanban
     * @param int                                                    $column_id Id of the column the item belongs to {@from query}
     * @param \Tuleap\AgileDashboard\REST\v1\OrderRepresentation     $order Order of the items {@from body}
     * @param \Tuleap\AgileDashboard\REST\v1\KanbanAddRepresentation $add   Ids to add to the column {@from body}
     */
    protected function patchItems(
        $id,
        $column_id,
        OrderRepresentation $order = null,
        KanbanAddRepresentation $add = null
    ) {
        $current_user = UserManager::instance()->getCurrentUser();
        $kanban       = $this->kanban_factory->getKanban($current_user, $id);

        if (! $this->columnIsInTracker($kanban, $current_user, $column_id)) {
            throw new RestException(404);
        }

        if ($add) {
            $add->checkFormat();
            $this->validateIdsInAddAreInKanbanTracker($kanban, $add);
            $this->resources_patcher->startTransaction();

            try {
                $this->moveArtifactsInColumn($kanban, $current_user, $add, $column_id);
                $this->resources_patcher->commit();
            } catch (Tracker_NoChangeException $exception) {
                $this->resources_patcher->rollback();
            } catch (Exception $exception) {
                $this->resources_patcher->rollback();
                throw new RestException(500, $exception->getMessage());
            }
        }


        if ($order) {
            $order->checkFormat();
            $kanban_column_items = $this->getItemsInColumn($kanban->getTrackerId(), $column_id);
            $order_validator     = new OrderValidator($kanban_column_items);

            try {
                $order_validator->validate($order);
            } catch (IdsFromBodyAreNotUniqueException $exception) {
                throw new RestException(409, $exception->getMessage());
            } catch (OrderIdOutOfBoundException $exception) {
                throw new RestException(409, $exception->getMessage());
            } catch (Exception $exception) {
                throw new RestException(500, $exception->getMessage());
            }

            $this->resources_patcher->updateArtifactPriorities(
                $order,
                Tracker_Artifact_PriorityHistoryChange::NO_CONTEXT,
                $this->tracker_factory->getTrackerById($kanban->getTrackerId())->getGroupId()
            );
        }

    }

    private function getItemsInColumn($tracker_id, $column_id) {
        $column_item_ids = array();
        foreach ($this->kanban_item_dao->getItemsInColumn($tracker_id, $column_id) as $artifact) {
            $column_item_ids[$artifact['id']] = true;
        }

        return $column_item_ids;
    }

    /**
     * @url OPTIONS {id}/items
     *
     * @param string $id Id of the milestone
     */
    public function optionsItems($id) {
        Header::allowOptionsGetPatch();
    }

    /** @return AgileDashboard_Kanban */
    private function getKanban(PFUser $user, $id) {
        try {
            $kanban = $this->kanban_factory->getKanban($user, $id);
        } catch (AgileDashboard_KanbanNotFoundException $exception) {
            throw new RestException(404);
        } catch (AgileDashboard_KanbanCannotAccessException $exception) {
            throw new RestException(403);
        }

        return $kanban;
    }

    private function getCurrentUser() {
        $user = UserManager::instance()->getCurrentUser();
        if (! $user->useLabFeatures()) {
            throw new RestException(403, 'You must activate lab features');
        }

        return $user;
    }

    private function getSemanticStatus(AgileDashboard_Kanban $kanban) {
        $tracker = TrackerFactory::instance()->getTrackerById($kanban->getTrackerId());
        if (! $tracker) {
            return;
        }

        $semantic = Tracker_Semantic_Status::load($tracker);
        if (! $semantic->getFieldId()) {
            return;
        }

        return $semantic;
    }
}
