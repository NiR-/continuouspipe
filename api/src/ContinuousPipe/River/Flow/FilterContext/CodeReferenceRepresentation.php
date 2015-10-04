<?php

namespace ContinuousPipe\River\Flow\FilterContext;

use ContinuousPipe\River\CodeReference;

class CodeReferenceRepresentation
{
    /**
     * @var string
     */
    public $branch;

    /**
     * @var string
     */
    public $sha1;

    /**
     * @param CodeReference $codeReference
     *
     * @return CodeReferenceRepresentation
     */
    public static function fromCodeReference(CodeReference $codeReference)
    {
        $self = new self();
        $self->branch = $codeReference->getBranch();
        $self->sha1 = $codeReference->getCommitSha();

        return $self;
    }
}
