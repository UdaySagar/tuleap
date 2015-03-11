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

class Admin_Homepage_Presenter {

    /** @var boolean */
    public $use_standard_homepage;

    /** @var Admin_Homepage_HeadlinePresenter[] */
    public $headlines;

    /** @var string */
    public $title;

    /** @var string */
    public $btn_submit;

    /** @var string html */
    public $csrf_token;

    /** @var string */
    public $headline;

    /** @var string */
    public $placeholder_headline;

    /** @var string */
    public $use_standard_homepage_label;

    public function __construct(
        CSRFSynchronizerToken $csrf,
        $title,
        $use_standard_homepage,
        array $headlines
    ) {
        $this->title                 = $title;
        $this->headlines             = $headlines;
        $this->csrf_token            = $csrf->fetchHTMLInput();
        $this->use_standard_homepage = $use_standard_homepage;

        $this->headline                    = $GLOBALS['Language']->getText('admin_main', 'headline');
        $this->btn_submit                  = $GLOBALS['Language']->getText('global', 'btn_submit');
        $this->headline_help               = $GLOBALS['Language']->getText('admin_main', 'headline_help');
        $this->placeholder_headline        = $GLOBALS['Language']->getText('admin_main', 'placeholder_headline');
        $this->use_standard_homepage_help  = $GLOBALS['Language']->getText('admin_main', 'use_standard_homepage_help');
        $this->use_standard_homepage_label = $GLOBALS['Language']->getText('admin_main', 'use_standard_homepage_label');
    }
}