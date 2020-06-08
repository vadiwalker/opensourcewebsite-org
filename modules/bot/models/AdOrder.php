<?php
namespace app\modules\bot\models;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Query;

class AdOrder extends ActiveRecord
{
    public const STATUS_OFF = 0;
    public const STATUS_ON = 1;

    public const LIVE_DAYS = 14;
    public const MAX_RADIUS = 10000;

    public static function tableName()
    {
        return 'ad_order';
    }

    public function rules()
    {
        return [
            [['user_id', 'category_id', 'title', 'description', 'currency_id', 'price', 'location_lat', 'location_lon', 'delivery_radius', 'status', 'created_at', 'renewed_at'], 'required'],
            [['title', 'description', 'location_lat', 'location_lon'], 'string'],
            [['user_id', 'currency_id', 'delivery_radius', 'category_id', 'status', 'created_at', 'renewed_at', 'edited_at'], 'integer'],
            [['price'], 'number'],
        ];
    }

    public function behaviors()
    {
        return [
            // TimestampBehavior::className(),
        ];
    }

    public function getKeywords()
    {
        return $this->hasMany(AdKeyword::className(), ['id' => 'ad_keyword_id'])
            ->viaTable('{{%ad_order_keyword}}', ['ad_order_id' => 'id']);
    }

    public function getPhotos()
    {
        return $this->hasMany(AdPhoto::className(), ['ad_order_id' => 'id']);
    }

    public function getStatusName()
    {
        switch ($this->status) {
            case self::STATUS_ON:
                return Yii::t('bot', 'Активно');
            case self::STATUS_OFF:
                return Yii::t('bot', 'Не активно');
            default:
                Yii::error('AdsPost status is invalid');
                return '';
        }
    }

    public function isActive()
    {
        return $this->status == self::STATUS_ON && (time() - $this->renewed_at) <= self::LIVE_DAYS * 24 * 60 * 60;
    }

    public function getMatches()
    {
        return $this->hasMany(AdSearch::className(), ['id' => 'ad_search_id'])
            ->viaTable('{{%ad_matches}}', ['ad_order_id' => 'id']);
    }

    public function updateMatches()
    {
        $this->unlinkAll('matches', true);

        if (!$this->isActive()) {
            return;
        }

        $adSearchQuery = AdSearch::find()
            ->where(['ad_search.status' => AdSearch::STATUS_ON])
            ->andWhere(['>=', 'ad_search.renewed_at', time() - AdSearch::LIVE_DAYS * 24 * 60 * 60])
            ->andWhere("st_distance_sphere(POINT($this->location_lat, $this->location_lon), POINT(ad_search.location_lat, ad_search.location_lon)) <= 1000 * (ad_search.pickup_radius + $this->delivery_radius)")
            ->joinWith(['keywords' => function ($query) {
                $query
                    ->joinWith('adOrders')
                    ->andWhere(['ad_order.id' => $this->id]);
            }])
            ->groupBy('ad_search.id');

        foreach ($adSearchQuery->all() as $adSearch) {
            $this->link('matches', $adSearch);
        }
    }

    public function markToUpdateMatches()
    {
        if ($this->edited_at === null) {
            $this->unlinkAll('matches', true);

            $this->setAttributes([
                'edited_at' => time(),
            ]);
            $this->save();
        }
    }

    public static function validatePrice($price)
    {
        return is_numeric($price) && round($price, 2) == $price && $price >= 0;
    }

    public static function validateLocation($location)
    {
        $slices = self::getLocationSlices(self::removeExtraChars($location));

        if (!isset($slices)) {
            return false;
        }

        $latitude = $slices[0];
        $longitude = $slices[1];

        return is_numeric($latitude) && is_numeric($longitude)
            && doubleval($latitude) >= -90 && doubleval($latitude) <= 90
            && doubleval($longitude) >= -180 && doubleval($longitude) <= 180;
    }

    public static function getLatitudeFromText($location)
    {
        $slices = self::getLocationSlices(self::removeExtraChars($location));

        return isset($slices) ? $slices[0] : null;
    }

    public static function getLongitudeFromText($location)
    {
        $slices = self::getLocationSlices(self::removeExtraChars($location));

        return isset($slices) ? $slices[1] : null;
    }

    public static function validateRadius($radius)
    {
        return is_numeric($radius) && $radius >= 0 && $radius <= self::MAX_RADIUS;
    }

    private static function removeExtraChars($str)
    {
        return preg_replace('/[^\d\.\- ]/', '', $str);
    }

    private static function getLocationSlices($location)
    {
        $slices = explode(' ', $location);

        if (count($slices) != 2) {
            return null;
        } else {
            return $slices;
        }
    }
}