<?php
/**
 * Copyright (c) Enalean, 2014. All Rights Reserved.
 *
 * This file is a part of Tuleap.
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
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */
require_once TRACKER_BASE_DIR . '/../tests/bootstrap.php';

class Tracker_XML_Exporter_ChangesetXMLExporterTest extends TuleapTestCase {

    /** @var Tracker_XML_Exporter_ChangesetXMLExporter */
    private $exporter;

    /** @var SimpleXMLElement */
    private $artifact_xml;

    /** @var Tracker_XML_Exporter_ChangesetValuesXMLExporter */
    private $values_exporter;

    /** @var Tracker_Artifact_ChangesetValue */
    private $values;

    /** @var Tracker_Artifact */
    private $artifact;

    public function setUp() {
        parent::setUp();
        $this->artifact_xml    = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><artifact />');
        $this->values_exporter = mock('Tracker_XML_Exporter_ChangesetValuesXMLExporter');
        $this->exporter        = new Tracker_XML_Exporter_ChangesetXMLExporter($this->values_exporter);

        $this->int_changeset_value   = new Tracker_Artifact_ChangesetValue_Integer('*', '*', '*', '*');
        $this->float_changeset_value = new Tracker_Artifact_ChangesetValue_Float('*', '*', '*', '*');
        $this->values = array(
            $this->int_changeset_value,
            $this->float_changeset_value
        );

        $this->artifact  = mock('Tracker_Artifact');
        $this->changeset = mock('Tracker_Artifact_Changeset');
        stub($this->changeset)->getValues()->returns($this->values);
        stub($this->changeset)->getArtifact()->returns($this->artifact);
    }

    public function itAppendsChangesetNodeToArtifactNode() {
        $this->exporter->exportWithoutComments($this->artifact_xml, $this->changeset);

        $this->assertEqual(count($this->artifact_xml->changeset), 1);
        $this->assertEqual(count($this->artifact_xml->changeset->submitted_by), 1);
        $this->assertEqual(count($this->artifact_xml->changeset->submitted_on), 1);
    }

    public function itDelegatesTheExportOfValues() {
        expect($this->values_exporter)->export($this->artifact_xml, '*', $this->artifact, $this->values)->once();

        $this->exporter->exportWithoutComments($this->artifact_xml, $this->changeset);
    }
}