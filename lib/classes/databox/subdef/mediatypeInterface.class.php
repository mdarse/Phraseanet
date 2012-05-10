<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2010 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 *
 * @package
 * @license     http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link        www.phraseanet.com
 */
interface databox_subdef_mediatypeInterface
{
  public function get_available_options(databox_subdefAbstract $subdef);

  public function get_options(databox_subdefAbstract $subdef);
}