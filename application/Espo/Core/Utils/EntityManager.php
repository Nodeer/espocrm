<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2015 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 ************************************************************************/

namespace Espo\Core\Utils;

use \Espo\Core\Exceptions\Error;
use \Espo\Core\Exceptions\Forbidden;
use \Espo\Core\Exceptions\Conflict;
use \Espo\Core\Utils\Json;

class EntityManager
{
    private $metadata;

    private $language;

    private $fileManager;

    private $metadataUtils;

    public function __construct(Metadata $metadata, Language $language, File\Manager $fileManager)
    {
        $this->metadata = $metadata;
        $this->language = $language;
        $this->fileManager = $fileManager;

        $this->metadataUtils = new \Espo\Core\Utils\Metadata\Utils($this->metadata);
    }

    protected function getMetadata()
    {
        return $this->metadata;
    }

    protected function getLanguage()
    {
        return $this->language;
    }

    protected function getFileManager()
    {
        return $this->fileManager;
    }

    protected function getMetadataUtils()
    {
        return $this->metadataUtils;
    }

    public function create($name, $type, $params = array())
    {
        if ($this->getMetadata()->get('scopes.' . $name)) {
            throw new Conflict('Entity ['.$name.'] already exists.');
        }
        if (empty($name) || empty($type)) {
            throw new Error();
        }

        $normalizedName = Util::normilizeClassName($name);

        $contents = "<" . "?" . "php\n".
            "namespace Espo\Custom\Entities;\n".
            "class {$normalizedName} extends \Espo\Core\Templates\Entities\\{$type}\n".
            "{\n".
            "}\n";

        $filePath = "custom/Espo/Custom/Entities/{$normalizedName}.php";
        $this->getFileManager()->putContents($filePath, $contents);

        $contents = "<" . "?" . "php\n".
            "namespace Espo\Custom\Controllers;\n".
            "class {$normalizedName} extends \Espo\Core\Templates\Controllers\\{$type}\n".
            "{\n".
            "}\n";
        $filePath = "custom/Espo/Custom/Controllers/{$normalizedName}.php";
        $this->getFileManager()->putContents($filePath, $contents);

        $contents = "<" . "?" . "php\n".
            "namespace Espo\Custom\Services;\n".
            "class {$normalizedName} extends \Espo\Core\Templates\Services\\{$type}\n".
            "{\n".
            "}\n";
        $filePath = "custom/Espo/Custom/Services/{$normalizedName}.php";
        $this->getFileManager()->putContents($filePath, $contents);

        $contents = "<" . "?" . "php\n".
            "namespace Espo\Custom\Repositories;\n".
            "class {$normalizedName} extends \Espo\Core\Templates\Repositories\\{$type}\n".
            "{\n".
            "}\n";

        $filePath = "custom/Espo/Custom/Repositories/{$normalizedName}.php";
        $this->getFileManager()->putContents($filePath, $contents);

        $stream = false;
        if (!empty($params['stream'])) {
            $stream = $params['stream'];
        }
        $labelSingular = $name;
        if (!empty($params['labelSingular'])) {
            $labelSingular = $params['labelSingular'];
        }
        $labelPlural = $name;
        if (!empty($params['labelPlural'])) {
            $labelPlural = $params['labelPlural'];
        }
        $labelCreate = $this->getLanguage()->translate('Create') . ' ' . $labelSingular;

        $scopeData = array(
            'entity' => true,
            'layouts' => true,
            'tab' => true,
            'acl' => true,
            'module' => 'Custom',
            'isCustom' => true,
            'customizable' => true,
            'importable' => true,
            'type' => $type,
            'stream' => $stream
        );
        $this->getMetadata()->set('scopes', $name, $scopeData);

        $filePath = "application/Espo/Core/Templates/Metadata/{$type}/entityDefs.json";
        $entityDefsData = Json::decode($this->getFileManager()->getContents($filePath), true);
        $this->getMetadata()->set('entityDefs', $name, $entityDefsData);

        $filePath = "application/Espo/Core/Templates/Metadata/{$type}/clientDefs.json";
        $clientDefsData = Json::decode($this->getFileManager()->getContents($filePath), true);
        $this->getMetadata()->set('clientDefs', $name, $clientDefsData);

        $this->getLanguage()->set('Global', 'scopeNames', $name, $labelSingular);
        $this->getLanguage()->set('Global', 'scopeNamesPlural', $name, $labelPlural);
        $this->getLanguage()->set($name, 'labels', 'Create ' . $name, $labelCreate);

        $this->getMetadata()->save();
        $this->getLanguage()->save();

        return true;
    }

    public function update($name, $data)
    {
        if (!$this->getMetadata()->get('scopes.' . $name)) {
            throw new Error('Entity ['.$name.'] does not exist.');
        }

        if (isset($data['stream'])) {
            $scopeData = array(
                'stream' => (true == $data['stream'])
            );
            $this->getMetadata()->set('scopes', $name, $scopeData);
        }

        if (!empty($data['labelSingular'])) {
            $labelSingular = $data['labelSingular'];
            $this->getLanguage()->set('Global', 'scopeNames', $name, $labelSingular);
            $labelCreate = $this->getLanguage()->translate('Create') . ' ' . $labelSingular;
            $this->getLanguage()->set($name, 'labels', 'Create ' . $name, $labelCreate);
        }

        if (!empty($data['labelPlural'])) {
            $labelPlural = $data['labelPlural'];
            $this->getLanguage()->set('Global', 'scopeNamesPlural', $name, $labelPlural);
        }

        $this->getMetadata()->save();
        $this->getLanguage()->save();

        return true;
    }

    public function delete($name)
    {
        if (!$this->isCustom($name)) {
            throw new Forbidden;
        }

        $normalizedName = Util::normilizeClassName($name);

        $unsets = array(
            'entityDefs',
            'clientDefs',
            'scopes'
        );
        $res = $this->getMetadata()->delete('entityDefs', $name);
        $res = $this->getMetadata()->delete('clientDefs', $name);
        $res = $this->getMetadata()->delete('scopes', $name);

        $this->getFileManager()->removeFile("custom/Espo/Custom/Resources/metadata/entityDefs/{$name}.json");
        $this->getFileManager()->removeFile("custom/Espo/Custom/Resources/metadata/clientDefs/{$name}.json");
        $this->getFileManager()->removeFile("custom/Espo/Custom/Resources/metadata/scopes/{$name}.json");

        $this->getFileManager()->removeFile("custom/Espo/Custom/Entities/{$normalizedName}.php");
        $this->getFileManager()->removeFile("custom/Espo/Custom/Services/{$normalizedName}.php");
        $this->getFileManager()->removeFile("custom/Espo/Custom/Controllers/{$normalizedName}.php");
        $this->getFileManager()->removeFile("custom/Espo/Custom/Repositories/{$normalizedName}.php");

        try {
            $this->getLanguage()->delete('Global', 'scopeNames', $name);
            $this->getLanguage()->delete('Global', 'scopeNamesPlural', $name);
        } catch (\Exception $e) {}

        $this->getMetadata()->save();
        $this->getLanguage()->save();

        return true;
    }

    protected function isCustom($name)
    {
        return $this->getMetadata()->get('scopes.' . $name . '.isCustom');
    }

    public function createLink(array $params)
    {
        $linkType = $params['linkType'];

        $entity = $params['entity'];
        $link = $params['link'];
        $entityForeign = $params['entityForeign'];
        $linkForeign = $params['linkForeign'];

        $label = $params['label'];
        $labelForeign = $params['labelForeign'];

        if (empty($linkType)) {
            throw new Error();
        }
        if (empty($entity) || empty($entityForeign)) {
            throw new Error();
        }
        if (empty($entityForeign) || empty($linkForeign)) {
            throw new Error();
        }

        if ($this->getMetadata()->get('entityDefs.' . $entity . '.links.' . $link)) {
            throw new Conflict('Link ['.$entity.'::'.$link.'] already exists.');
        }
        if ($this->getMetadata()->get('entityDefs.' . $entityForeign . '.links.' . $linkForeign)) {
            throw new Conflict('Link ['.$entityForeign.'::'.$linkForeign.'] already exists.');
        }

        switch ($linkType) {
            case 'oneToMany':
                if ($this->getMetadata()->get('entityDefs.' . $entityForeign . '.field.' . $linkForeign)) {
                    throw new Conflict('Field ['.$entityForeign.'::'.$linkForeign.'] already exists.');
                }
                $dataLeft = array(
                    'links' => array(
                        $link => array(
                            'type' => 'hasMany',
                            'foreign' => $linkForeign,
                            'entity' => $entityForeign,
                            'isCustom' => true
                        )
                    )
                );
                $dataRight = array(
                    'fields' => array(
                        $linkForeign => array(
                            'type' => 'link'
                        )
                    ),
                    'links' => array(
                        $linkForeign => array(
                            'type' => 'belongsTo',
                            'foreign' => $link,
                            'entity' => $entity,
                            'isCustom' => true
                        )
                    )
                );
                break;
            case 'manyToOne':
                if ($this->getMetadata()->get('entityDefs.' . $entity . '.field.' . $link)) {
                    throw new Conflict('Field ['.$entity.'::'.$link.'] already exists.');
                }
                $dataLeft = array(
                    'fields' => array(
                        $link => array(
                            'type' => 'link'
                        )
                    ),
                    'links' => array(
                        $link => array(
                            'type' => 'belongsTo',
                            'foreign' => $linkForeign,
                            'entity' => $entityForeign,
                            'isCustom' => true
                        )
                    )
                );
                $dataRight = array(
                    'links' => array(
                        $linkForeign => array(
                            'type' => 'hasMany',
                            'foreign' => $link,
                            'entity' => $entity,
                            'isCustom' => true
                        )
                    )
                );
                break;
            case 'manyToMany':
                $dataLeft = array(
                    'links' => array(
                        $link => array(
                            'type' => 'hasMany',
                            'foreign' => $linkForeign,
                            'entity' => $entityForeign,
                            'isCustom' => true
                        )
                    )
                );
                $dataRight = array(
                    'links' => array(
                        $linkForeign => array(
                            'type' => 'hasMany',
                            'foreign' => $link,
                            'entity' => $entity,
                            'isCustom' => true
                        )
                    )
                );
                break;
        }

        $this->getMetadata()->set('entityDefs', $entity, $dataLeft);
        $this->getMetadata()->set('entityDefs', $entityForeign, $dataRight);
        $this->getMetadata()->save();

        $this->getLanguage()->set($entity, 'fields', $link, $label);
        $this->getLanguage()->set($entity, 'links', $link, $label);
        $this->getLanguage()->set($entityForeign, 'fields', $linkForeign, $labelForeign);
        $this->getLanguage()->set($entityForeign, 'links', $linkForeign, $labelForeign);

        $this->getLanguage()->save();

        return true;
    }

    public function updateLink(array $params)
    {
        $entity = $params['entity'];
        $link = $params['link'];
        $entityForeign = $params['entityForeign'];
        $linkForeign = $params['linkForeign'];

        $label = $params['label'];
        $labelForeign = $params['labelForeign'];

        if (empty($entity) || empty($entityForeign)) {
            throw new Error();
        }
        if (empty($entityForeign) || empty($linkForeign)) {
            throw new Error();
        }

        $this->getLanguage()->set($entity, 'fields', $link, $label);
        $this->getLanguage()->set($entity, 'links', $link, $label);
        $this->getLanguage()->set($entityForeign, 'fields', $linkForeign, $labelForeign);
        $this->getLanguage()->set($entityForeign, 'links', $linkForeign, $labelForeign);

        $this->getLanguage()->save();

        return true;
    }

    public function deleteLink(array $params)
    {
        $entity = $params['entity'];
        $link = $params['link'];

        if (!$this->getMetadata()->get("entityDefs.{$entity}.links.{$link}.isCustom")) {
            throw new Error();
        }

        $entityForeign = $this->getMetadata()->get("entityDefs.{$entity}.links.{$link}.entity");
        $linkForeign = $this->getMetadata()->get("entityDefs.{$entity}.links.{$link}.foreign");

        if (empty($entity) || empty($entityForeign)) {
            throw new Error();
        }
        if (empty($entityForeign) || empty($linkForeign)) {
            throw new Error();
        }

        $this->getMetadata()->delete('entityDefs', $entity, array(
            'fields.' . $link,
            'links.' . $link
        ));
        $this->getMetadata()->delete('entityDefs', $entityForeign, array(
            'fields.' . $linkForeign,
            'links.' . $linkForeign
        ));
        $this->getMetadata()->save();

        return true;
    }
}