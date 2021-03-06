<?php

namespace ContinuousPipe\Builder;

use ContinuousPipe\Builder\Archive\ArchiveException;
use Docker\Context\ContextInterface;

interface Archive extends ContextInterface
{
    const TAR = 'tar';
    const TAG_GZ = 'targz';

    /**
     * Delete the archive.
     */
    public function delete();

    /**
     * Write the archive content at the given path.
     *
     * @param string $path
     * @param Archive $archive
     *
     * @throws ArchiveException
     */
    public function write(string $path, Archive $archive);

    /**
     * Write the file at the given path.
     *
     * @param string $path
     * @param string $contents
     *
     * @throws ArchiveException
     */
    public function writeFile(string $path, string $contents);

    /**
     * Returns true if the given file or folder exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function contains(string $path) : bool;
}
