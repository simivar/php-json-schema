<?php

namespace Swaggest\JsonSchema;

use Swaggest\JsonSchema\Constraint\Ref;
use Swaggest\JsonSchema\RemoteRef\BasicFetcher;

class RefResolver
{
    private $resolutionScope = '';
    private $url;
    /** @var RefResolver */
    private $rootResolver;

    /**
     * @param mixed $resolutionScope
     * @return string previous value
     */
    public function setResolutionScope($resolutionScope)
    {
        $rootResolver = $this->rootResolver ? $this->rootResolver : $this;
        $prev = $rootResolver->resolutionScope;
        $rootResolver->resolutionScope = $resolutionScope;
        return $prev;
    }

    public function updateResolutionScope($id)
    {
        $rootResolver = $this->rootResolver ? $this->rootResolver : $this;
        $prev = $rootResolver->resolutionScope;
        if (strpos($id, '://') !== false) {
            $rootResolver->resolutionScope = $id;
        } else {
            $rootResolver->resolutionScope = Helper::resolveURI($rootResolver->resolutionScope, $id);
        }

        return $prev;
    }

    public function setupResolutionScope($id, $data)
    {
        $rootResolver = $this->rootResolver ? $this->rootResolver : $this;

        $prev = $this->updateResolutionScope($id);

        $refParts = explode('#', $this->resolutionScope, 2);
        if ($refParts[0] && empty($refParts[1])) {
            if (!isset($rootResolver->remoteRefResolvers[$refParts[0]])) {
                $resolver = new RefResolver($data);
                $resolver->rootResolver = $rootResolver;
                $resolver->url = $refParts[0];
                $this->remoteRefResolvers[$refParts[0]] = $resolver;
            }
        }

        return $prev;
    }

    private $rootData;

    /** @var Ref[] */
    private $refs = array();

    /** @var RefResolver[] */
    private $remoteRefResolvers = array();

    /** @var RemoteRefProvider */
    private $refProvider;

    /**
     * RefResolver constructor.
     * @param $rootData
     */
    public function __construct($rootData)
    {
        $this->rootData = $rootData;
    }


    public function setRemoteRefProvider(RemoteRefProvider $provider)
    {
        $this->refProvider = $provider;
        return $this;
    }

    private function getRefProvider()
    {
        if (null === $this->refProvider) {
            $this->refProvider = new BasicFetcher();
        }
        return $this->refProvider;
    }


    /**
     * @param $referencePath
     * @return Ref
     * @throws \Exception
     */
    public function resolveReference($referencePath)
    {
        if ($this->resolutionScope) {
            $referencePath = Helper::resolveURI($this->resolutionScope, $referencePath);
        }

        $refParts = explode('#', $referencePath, 2);
        $url = rtrim($refParts[0], '#');
        $refLocalPath = isset($refParts[1]) ? '#' . $refParts[1] : '#';

        if ($url === $this->url) {
            $referencePath = $refLocalPath;
        }

        $ref = &$this->refs[$referencePath];

        $refResolver = $this;

        if (null === $ref) {
            if ($referencePath[0] === '#') {
                if ($referencePath === '#') {
                    $ref = new Ref($referencePath, $this->rootData);
                } else {
                    $ref = new Ref($referencePath);
                    $path = explode('/', trim($referencePath, '#/'));
                    $branch = &$this->rootData;
                    while (!empty($path)) {
                        $folder = array_shift($path);

                        // unescaping special characters
                        // https://tools.ietf.org/html/draft-ietf-appsawg-json-pointer-07#section-4
                        // https://github.com/json-schema-org/JSON-Schema-Test-Suite/issues/130
                        $folder = str_replace(array('~0', '~1', '%25'), array('~', '/', '%'), $folder);

                        if ($branch instanceof \stdClass && isset($branch->$folder)) {
                            $branch = &$branch->$folder;
                        } elseif (is_array($branch) && isset($branch[$folder])) {
                            $branch = &$branch[$folder];
                        } else {
                            throw new InvalidValue('Could not resolve ' . $referencePath . '@' . $this->resolutionScope . ': ' . $folder);
                        }
                    }
                    $ref->setData($branch);
                }
            } else {
                if ($url !== $this->url) {
                    $rootResolver = $this->rootResolver ? $this->rootResolver : $this;
                    $refResolver = &$rootResolver->remoteRefResolvers[$url];
                    if (null === $refResolver) {
                        $rootData = $rootResolver->getRefProvider()->getSchemaData($url);
                        $refResolver = new RefResolver($rootData);
                        $refResolver->rootResolver = $rootResolver;
                        $refResolver->refProvider = $this->refProvider;
                        $refResolver->url = $url;
                    }
                }

                $ref = $refResolver->resolveReference($refLocalPath);
            }
        }

        $refData = $ref->getData();
        // we need to go deeper
        if (isset($refData->{'$ref'})) {
            return $refResolver->resolveReference($refData->{'$ref'});
        }

        return $ref;
    }


}