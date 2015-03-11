<?php
/**
  * Copyright (c) Enalean, 2015. All Rights Reserved.
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
  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  * GNU General Public License for more details.
  *
  * You should have received a copy of the GNU General Public License
  * along with Tuleap. If not, see <http://www.gnu.org/licenses/
  */

class Admin_Homepage_Controller {

    /**
     * @var Response
     */
    private $response;

    /**
     * @var Codendi_Request
     */
    private $request;

    /**
     * @var Admin_Homepage_Dao
     */
    private $dao;

    /**
     * @var CSRFSynchronizerToken
     */
    private $csrf;

    const TEMPLATE = 'admin';

    public function __construct(
        CSRFSynchronizerToken $csrf,
        Admin_Homepage_Dao $dao,
        Codendi_Request $request,
        Response $response
    ) {
        $this->csrf     = $csrf;
        $this->dao      = $dao;
        $this->request  = $request;
        $this->response = $response;
    }

    public function index() {
        $title  = $GLOBALS['Language']->getText('admin_main', 'configure_homepage');
        $params = array(
            'title' => $title
        );
        $renderer  = TemplateRendererFactory::build()->getRenderer($this->getTemplateDir());
        $headlines = $this->getHeadlines();

        $this->response->includeFooterJavascriptFile('/scripts/tuleap/admin-homepage.js');
        $this->response->header($params);
        $renderer->renderToPage(
            self::TEMPLATE,
            new Admin_Homepage_Presenter(
                $this->csrf,
                $title,
                $this->dao->isStandardHomepageUsed(),
                $headlines
            )
        );
        $this->response->footer($params);
    }

    public function update() {
        $this->csrf->check();

        if ($this->request->get('use_standard_homepage')) {
            $this->dao->useStandardHomepage();
        } else {
            $this->dao->doNotUseStandardHomepage();
        }

        $headlines = $this->request->get('headlines');
        if (is_array($headlines)) {
            $this->dao->save($headlines);
        }

        $this->response->addFeedback(Feedback::INFO, $GLOBALS['Language']->getText('admin_main', 'successfully_updated'));
        $this->redirectToIndex();
    }

    public function notSiteAdmin() {
        $this->response->redirect(get_server_url());
    }

    private function getTemplateDir() {
        return Config::get('codendi_dir') .'/src/templates/homepage/';
    }

    private function getHeadlines() {
        $headlines = array();
        foreach ($this->dao->searchHeadlines() as $row) {
            $headlines[] = new Admin_Homepage_HeadlinePresenter(
                $row['language_id'],
                $row['headline']
            );
        }

        return $headlines;
    }

    private function redirectToIndex() {
        $this->response->redirect($_SERVER['SCRIPT_URL']);
    }
}