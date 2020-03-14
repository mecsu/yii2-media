<?php

namespace wdmg\media\models;

use Yii;
use yii\db\Expression;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\base\InvalidArgumentException;
use yii\behaviors\TimestampBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\SluggableBehavior;
use wdmg\validators\JsonValidator;
use wdmg\media\models\Categories;

/**
 * This is the model class for table "{{%media}}".
 *
 * @property int $id
 * @property int $cat_id
 * @property string $name
 * @property string $alias
 * @property string $path
 * @property int $size
 * @property string $title
 * @property string $caption
 * @property string $alt
 * @property string $description
 * @property string $mime_type
 * @property string $params
 * @property string $reference
 * @property boolean $status
 * @property string $created_at
 * @property integer $created_by
 * @property string $updated_at
 * @property integer $updated_by
 */
class Media extends ActiveRecord
{
    public $route;

    const MEDIA_STATUS_DRAFT = 0; // Media has draft
    const MEDIA_STATUS_PUBLISHED = 1; // Media has been published

    public $file;
    public $url;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%media}}';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => 'created_at',
                    ActiveRecord::EVENT_BEFORE_UPDATE => 'updated_at',
                ],
                'value' => new Expression('NOW()'),
            ],
            'blameable' => [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'created_by',
                'updatedByAttribute' => 'updated_by',
            ],
            'sluggable' =>  [
                'class' => SluggableBehavior::class,
                'attribute' => ['name'],
                'slugAttribute' => 'alias',
                'ensureUnique' => true,
                'skipOnEmpty' => true,
                'immutable' => true,
                'value' => function ($event) {
                    return mb_substr($this->name, 0, 32);
                }
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        $rules = [
            [['name', 'alias', 'content'], 'required'],
            [['name', 'alias', 'mime_type'], 'string', 'min' => 3, 'max' => 128],
            [['path', 'title', 'alt', 'reference'], 'string', 'max' => 255],
            [['caption'], 'string', 'max' => 550],
            [['description'], 'string'],
            [['cat_id', 'size'], 'integer'],
            [['file'], 'file', 'skipOnEmpty' => true, 'maxFiles' => 1, 'extensions' => 'png, jpg'],
            [['params'], JsonValidator::class, 'message' => Yii::t('app/modules/media', 'The value of field `{attribute}` must be a valid JSON, error: {error}.')],
            [['status'], 'boolean'],
            ['alias', 'unique', 'message' => Yii::t('app/modules/media', 'Param attribute must be unique.')],
            ['alias', 'match', 'pattern' => '/^[A-Za-z0-9\-\_]+$/', 'message' => Yii::t('app/modules/media','It allowed only Latin alphabet, numbers and the «-», «_» characters.')],
            [['source', 'created_at', 'updated_at'], 'safe'],
        ];

        if (class_exists('\wdmg\users\models\Users')) {
            $rules[] = [['created_by', 'updated_by'], 'safe'];
        }

        return $rules;
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app/modules/media', 'ID'),
            'cat_id' => Yii::t('app/modules/media', 'Category ID'),
            'name' => Yii::t('app/modules/media', 'Name'),
            'alias' => Yii::t('app/modules/media', 'Alias'),
            'path' => Yii::t('app/modules/media', 'File path'),
            'size' => Yii::t('app/modules/media', 'File size'),
            'title' => Yii::t('app/modules/media', 'Title'),
            'caption' => Yii::t('app/modules/media', 'Caption'),
            'alt' => Yii::t('app/modules/media', 'Alternate'),
            'description' => Yii::t('app/modules/media', 'Description'),
            'mime_type' => Yii::t('app/modules/media', 'Mime type'),
            'params' => Yii::t('app/modules/media', 'Params'),
            'reference' => Yii::t('app/modules/media', 'Reference'),
            'status' => Yii::t('app/modules/media', 'Status'),
            'created_at' => Yii::t('app/modules/media', 'Created at'),
            'created_by' => Yii::t('app/modules/media', 'Created by'),
            'updated_at' => Yii::t('app/modules/media', 'Updated at'),
            'updated_by' => Yii::t('app/modules/media', 'Updated by'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function afterFind()
    {
        parent::afterFind();

        if (is_null($this->url))
            $this->url = $this->getUrl();

        if (is_array($this->params)) {
            $this->params = \yii\helpers\Json::encode($this->tags);
        }

    }

    public function beforeValidate()
    {
        if (is_string($this->params) && JsonValidator::isValid($this->params)) {
            $this->params = \yii\helpers\Json::decode($this->params);
        } elseif (is_array($this->params)) {
            $this->params = \yii\helpers\Json::encode($this->params);
        }

        return parent::beforeValidate();
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {

        if (is_string($this->params) && JsonValidator::isValid($this->params)) {
            $this->params = \yii\helpers\Json::decode($this->params);
        }

        // Set default category if category not be selected
        if ($insert && empty($this->cat_id))
            $this->cat_id = [1];

        return parent::beforeSave($insert);
    }

    /**
     * @return string
     */
    public function getMediaPath($absoluteUrl = false)
    {

        if (isset(Yii::$app->params["media.mediaPath"])) {
            $mediaPath = Yii::$app->params["media.mediaPath"];
        } else {

            if (!$module = Yii::$app->getModule('admin/media'))
                $module = Yii::$app->getModule('media');

            $mediaPath = $module->mediaPath;
        }

        if ($absoluteUrl)
            return \yii\helpers\Url::to(str_replace('\\', '/', $mediaPath), true);
        else
            return $mediaPath;

    }

    /**
     * @return object of \yii\db\ActiveQuery
     */
    public function getCreatedBy()
    {
        if (class_exists('\wdmg\users\models\Users'))
            return $this->hasOne(\wdmg\users\models\Users::class, ['id' => 'created_by']);
        else
            return $this->created_by;
    }

    /**
     * @return object of \yii\db\ActiveQuery
     */
    public function getUpdatedBy()
    {
        if (class_exists('\wdmg\users\models\Users'))
            return $this->hasOne(\wdmg\users\models\Users::class, ['id' => 'updated_by']);
        else
            return $this->updated_by;
    }

    public function upload($file = null)
    {
        if (!$file)
            return false;

        $path = Yii::getAlias('@webroot') . $this->getMediaPath();
        if ($file) {
            // Create the folder if not exist
            if (\yii\helpers\FileHelper::createDirectory($path, $mode = 0775, $recursive = true)) {
                $fileName = $file->baseName . '.' . $file->extension;
                if ($file->saveAs($path . '/' . $fileName)) {
                    $this->path = $fileName;
                    $this->mime_type = $file->mimeType;
                    return $fileName;
                }
            }
        }
        return false;
    }

    /**
     * Returns published media items
     *
     * @param null $cond sampling conditions
     * @param bool $asArray flag if necessary to return as an array
     * @return array|ActiveRecord|null
     */
    public function getPublished($cond = null, $asArray = false) {
        if (!is_null($cond) && is_array($cond))
            $models = self::find()->where(ArrayHelper::merge($cond, ['status' => self::MEDIA_STATUS_PUBLISHED]));
        elseif (!is_null($cond) && is_string($cond))
            $models = self::find()->where(ArrayHelper::merge([$cond], ['status' => self::MEDIA_STATUS_PUBLISHED]));
        else
            $models = self::find()->where(['status' => self::MEDIA_STATUS_PUBLISHED]);

        if ($asArray)
            return $models->asArray()->all();
        else
            return $models->all();

    }

    /**
     * Returns all media posts (draft and published)
     *
     * @param null $cond sampling conditions
     * @param bool $asArray flag if necessary to return as an array
     * @return array|ActiveRecord|null
     */
    public function getAll($cond = null, $asArray = false) {
        if (!is_null($cond))
            $models = self::find()->where($cond);
        else
            $models = self::find();

        if ($asArray)
            return $models->asArray()->all();
        else
            return $models->all();

    }

    /**
     * @return object of \yii\db\ActiveQuery
     */
    public function getCategories($cat_id = null, $asArray = false) {

        if (!($cat_id === false) && !is_integer($cat_id) && !is_string($cat_id))
            $cat_id = $this->cat_id;

        $query = Categories::find()->alias('cats');

        if (is_integer($cat_id))
            $query->andWhere([
                'id' => intval($cat_id)
            ]);

        if ($asArray)
            return $query->asArray()->all();
        else
            return $query->all();

    }

    /**
     * @return string
     */
    public function getRoute()
    {
        if (isset(Yii::$app->params["media.mediaRoute"])) {
            $route = Yii::$app->params["media.mediaRoute"];
        } else {

            if (!$module = Yii::$app->getModule('admin/media'))
                $module = Yii::$app->getModule('media');

            $route = $module->mediaRoute;
        }

        return $route;
    }

    /**
     *
     * @param $withScheme boolean, absolute or relative URL
     * @return string or null
     */
    public function getMediaUrl($withScheme = true, $realUrl = false)
    {
        $this->route = $this->getRoute();
        if (isset($this->alias)) {
            if ($this->status == self::MEDIA_STATUS_DRAFT && $realUrl)
                return \yii\helpers\Url::to(['default/view', 'alias' => $this->alias, 'draft' => 'true'], $withScheme);
            else
                return \yii\helpers\Url::to($this->route . '/' .$this->alias, $withScheme);

        } else {
            return null;
        }
    }

    /**
     * Returns the URL to the view of the current model
     *
     * @return string
     */
    public function getUrl()
    {
        if ($this->url === null)
            $this->url = $this->getMediaUrl();

        return $this->url;
    }

    /**
     * @return array
     */
    public function getStatusesList($allStatuses = false)
    {
        if ($allStatuses)
            return [
                '*' => Yii::t('app/modules/media', 'All statuses'),
                self::MEDIA_STATUS_DRAFT => Yii::t('app/modules/media', 'Draft'),
                self::MEDIA_STATUS_PUBLISHED => Yii::t('app/modules/media', 'Published'),
            ];
        else
            return [
                self::MEDIA_STATUS_DRAFT => Yii::t('app/modules/media', 'Draft'),
                self::MEDIA_STATUS_PUBLISHED => Yii::t('app/modules/media', 'Published'),
            ];
    }

    /**
     * @return array
     */
    public function getCategoriesList()
    {
        $list = [];
        if ($categories = $this->getCategories(null, true)) {
            $list = ArrayHelper::merge($list, ArrayHelper::map($categories, 'id', 'name'));
        }

        return $list;
    }

    /**
     * @return object of \yii\db\ActiveQuery
     */
    public function getAllCategories($cond = null, $select = ['id', 'name'], $asArray = false)
    {
        if ($cond) {
            if ($asArray)
                return Categories::find()->select($select)->where($cond)->asArray()->indexBy('id')->all();
            else
                return Categories::find()->select($select)->where($cond)->all();

        } else {
            if ($asArray)
                return Categories::find()->select($select)->asArray()->indexBy('id')->all();
            else
                return Categories::find()->select($select)->all();
        }
    }

    /**
     * @return array
     */
    public function getAllCategoriesList($allCategories = false)
    {
        $list = [];
        if ($allCategories)
            $list['*'] = Yii::t('app/modules/media', 'All categories');

        if ($categories = $this->getAllCategories(null, ['id', 'name'], true)) {
            $list = ArrayHelper::merge($list, ArrayHelper::map($categories, 'id', 'name'));
        }

        return $list;
    }

}
