<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Http\StaticFile;

class NullMode implements StaticFileModeInterface
{
    /**
     * {@inheritdoc}
     */
    public function getVirtualHostConfiguration()
    {
        return "\n";
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl($pathFile)
    {
    }
}
