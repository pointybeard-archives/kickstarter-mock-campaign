<?php

namespace pointybeard\Kickstarter\MockCampaign\Lib;

use Faker;
use pointybeard\Kickstarter\ExportParser\Lib\Models\Record;

class Generator
{
    public static $noRewardHeaderFields = [
        "Backer Number",
        "Backer UID",
        "Backer Name",
        "Email",
        "Pledge Amount",
        "Pledged At",
        "Pledged Status",
        "Notes",
        "Billing State/Province",
        "Billing Country",
    ];

    public static $rewardHeaderFields = [
        "Backer Number",
        "Backer UID",
        "Backer Name",
        "Email",
        "Shipping Country",
        "Shipping Amount",
        "Reward Title",
        "Reward Minimum",
        "Reward ID",
        "Pledge Amount",
        "Pledged At",
        "Rewards Sent?",
        "Pledged Status",
        "Notes",
        "Billing State/Province",
        "Billing Country",
        "Survey Response",
        "Shipping Name",
        "Shipping Address 1",
        "Shipping Address 2",
        "Shipping City",
        "Shipping State",
        "Shipping Postal Code",
        "Shipping Country Name",
        "Shipping Country Code",
        "Shipping Phone Number",
        "Shipping Delivery Notes",
    ];

    public static function generate(\stdClass $settings) : string
    {
        // A Kicstarter export is a zip file containing 1 CSV for
        // each pledge level + a "no reward" CSV

        // The CSV files have a filename pattern like so:
        // `[PLEDGE AMOUNT] [CURRENCY] [REWARD NAME] - [DATE OF EXPORT].csv`
        // For example:
        // 59.00 USD Expansion + Base Game - Oct 25 10pm.csv
        //
        // The No Reward CSV is just called `No reward - [DATE].csv`

        // distribution-reference.json
        $distributionReference = json_decode(file_get_contents(
            __DIR__ . "/../../distribution-reference.json"
        ));

        $backersPerCountry = [];
        foreach ($distributionReference->countriesByPercentage as $code => $percentage) {
            $backersPerCountry[$code] = ceil($settings->basics->backers * ($percentage * 1/100));
        }

        $settings->basics->totalBackersWithRewards = 0;
        $settings->basics->totalAllocatedFunds = 0;

        foreach ($settings->rewards as $ii => $reward) {
            $backerCount = ceil($reward->backerPercentage * $settings->basics->backers);

            $settings->rewards[$ii] = (object)array_merge((array)$reward, [
                'backers' => $backerCount,
                'minimumFunds' => $reward->minimumPledge * $backerCount
            ]);

            $settings->basics->totalBackersWithRewards += $backerCount;
            $settings->basics->totalAllocatedFunds += $settings->rewards[$ii]->minimumFunds;
        }

        // Create backers and assign them to countries based on the reference distribution
        $faker = Faker\Factory::create();

        $zip = new \ZipArchive;
        $archiveTmpFile = tempnam(sys_get_temp_dir(), 'ks_mock_data');
        $res = $zip->open($archiveTmpFile, \ZipArchive::CREATE);

        if ($res !== true) {
            throw new \Exception('Failed to create zip archive.');
        }

        $totalBackersCreated = 0;

        // Since we cannot keep thousands of backer objects in memory, we need
        // to generate one and then immediately write it to disk. Once done,
        // we can add them to the zip archive.

        foreach ($settings->rewards as $reward) {
            $rewardTmpFile = tempnam(sys_get_temp_dir(), 'ks_mock_data-reward');
            $fp = fopen($rewardTmpFile, 'w');

            $rewardBackersRemaining = ceil($settings->basics->backers * ($reward->backerPercentage / 100.0));
            $first = true;

            printf('Generating reward "%s" [%d backers]' . PHP_EOL, $reward->name, $rewardBackersRemaining);

            $headers = self::$rewardHeaderFields;

            if (!isset($reward->shipping)) {
                $headers = array_flip($headers);
                unset($headers["Shipping Country"]);
                unset($headers["Shipping Amount"]);
                $headers = array_flip($headers);
            }

            do {
                $billingCountry = self::pickRandomCountryFromRemainingPool($backersPerCountry);
                $shippingAmount = 0.0;

                if (isset($reward->shipping)) {
                    $shippingAmount = self::findShippingRate($billingCountry, $settings->zones, $reward->shipping);
                }

                $backer = self::createGenericBacker($faker, null, $totalBackersCreated+1);
                $backer = self::generateAddress($faker, $settings->countries, $backer);
                $backer = self::generatePledge(
                    $faker,
                    $settings->countries,
                    $backer,
                    $settings->basics->currency,
                    $settings->basics->currencySign,
                    $shippingAmount,
                    $reward->minimumPledge,
                    $reward->minimumPledge + $shippingAmount + self::calculateAddons($settings->addons, $distributionReference->addonPercentage),
                    $billingCountry,
                    $reward->name
                );

                $backer = self::generateSurveyAnswers($faker, $reward->survey, $backer, $distributionReference->surveyAnswerPercentage);

                $backer->toCsv($fp, $first, $headers);
                $first = false;

                if ($rewardBackersRemaining % 50 == 0) {
                    print ".";
                }

                $rewardBackersRemaining--;
                $totalBackersCreated++;
            } while ($rewardBackersRemaining > 0);

            print PHP_EOL;

            fclose($fp);

            $zip->addFile(
                $rewardTmpFile,
                self::generateRewardFileName($reward, $settings->basics->currency)
            );
        }

        // Now do the no reward rewards
        $noRewardTmpFile = tempnam(sys_get_temp_dir(), 'ks_mock_data-noreward');
        $fp = fopen($noRewardTmpFile, 'w');

        fputcsv($fp, self::$noRewardHeaderFields);

        // Create the "No Reward" pledge
        $totalNoRewardBackers = ($settings->basics->backers - $totalBackersCreated);
        $noRewardReward = (object)[
           "name" => "No Reward",
           "minimumPledge" => 1,
           "backers" => $totalNoRewardBackers,
           "minimumFunds" => $totalNoRewardBackers,
           "backerPercentage" => 100 * ($totalNoRewardBackers / $settings->basics->backers)
        ];

        $settings->basics->totalAllocatedFunds += $noRewardReward->minimumFunds;
        $settings->basics->totalUnallocatedFunds = $settings->basics->funds - $settings->basics->totalAllocatedFunds;

        $rewardBackersRemaining = $noRewardReward->backers;
        printf('Generating reward "%s" [%d backers]' . PHP_EOL, "No Reward", $rewardBackersRemaining);
        do {
            $backer = self::createGenericBacker($faker, null, $totalBackersCreated+1);

            $billingCountry = self::pickRandomCountryFromRemainingPool($backersPerCountry);
            $backer = self::generatePledge(
                $faker,
                $settings->countries,
                $backer,
                $settings->basics->currency,
                $settings->basics->currencySign,
                0,
                $noRewardReward->minimumPledge,
                $noRewardReward->minimumPledge + self::calculateAddons($settings->addons, $distributionReference->addonPercentage),
                $billingCountry,
                "No Reward"
            );

            $backer->toCsv($fp, false, self::$noRewardHeaderFields);

            if ($rewardBackersRemaining % 50 == 0) {
                print ".";
            }

            $rewardBackersRemaining--;
            $totalBackersCreated++;
        } while ($rewardBackersRemaining > 0);

        print PHP_EOL;

        fclose($fp);

        $zip->addFile(
            $noRewardTmpFile,
            self::generateRewardFileName($noRewardReward, $settings->basics->currency)
        );

        $zip->close();

        return $archiveTmpFile;
    }

    protected static function pickRandomCountryFromRemainingPool(array &$pool) : string
    {

        // We need to weight the pool so it's more likely to pick a country
        // with a large number of backers remaining.

        // Find how many backers are still in the pool;
        $total = array_sum($pool);

        // Generate percentage chance that a backer is from a particular country
        // This effectively normalised the values and gives us a total of about
        // 100 distributed across the countries.
        $likelinessTable = [];
        foreach ($pool as $code => $count) {
            $likelinessTable[$code] = ceil(($count * (1 / $total)) * 100);
        }

        // Generate a lookup by adding codes equal to it's likeliness score.
        // E.g. US has a likeliness of 15, so add 'US' 15 times to the lookup.
        $lookupTable = [];
        foreach ($likelinessTable as $code => $count) {
            $lookupTable = array_merge($lookupTable, array_pad([], $count, $code));
        }

        $codes = array_keys($pool);
        do {
            // Pick a random item from the lookup
            $selected = $lookupTable[rand(0, count($lookupTable) - 1)];
        } while ($pool[$selected] <= 0);

        // Reduce the pool
        $pool[$selected]--;

        return $selected;
    }

    protected static function generateSurveyAnswers(object $faker, array $survey, Record $record=null, $hitChance=70.0) : Record
    {
        $answered = self::isHit($hitChance);

        foreach ($survey as $s) {
            if (!isset($s->options)) {
                $s->options = null;
            }

            $answer = !$answered
                ? null
                : self::generateAnswer($faker, $s->options);

            if (!$answered) {
                $record->setField("Survey Response", null);
            }

            $record->setField($s->question, $answer);
        }

        return $record;
    }

    protected static function generateAnswer(object $faker, array $options=null)
    {
        if ($options != null) {
            return $options[rand(0, count($options) - 1)];
        }

        return $faker->realText(50);
    }

    protected static function isHit($chance) : bool
    {
        return (rand(1, 100) <= $chance);
    }

    protected static function calculateAddons(array $addons, $hitChance=10.0, array $possibleAddonCount=[1, 1, 1, 1, 1, 2, 2, 3, 4]) : float
    {
        $total = 0.0;

        // Simple way to see if we have a hit.
        if (!self::isHit($hitChance)) {
            return $total;
        }

        $addonCount = $possibleAddonCount[rand(0, count($possibleAddonCount) - 1)];
        do {
            $total += $addons[rand(0, count($addons) - 1)];
            $addonCount--;
        } while ($addonCount > 0);

        return $total;
    }

    protected static function generateRewardFileName($reward, $currency="AUD") : string
    {
        if ($reward->name == "No Reward") {
            return sprintf("No reward - %s.csv", date("M d ha"));
        }

        return sprintf(
            "%0.2f %s %s - %s.csv",
            $reward->minimumPledge,
            $currency,
            $reward->name,
            date("M d ha")
        );
    }

    protected static function findShippingRate($country, array $zones, array $shipping) : ?float
    {
        $zoneName = null;
        foreach ($zones as $z) {
            if (in_array($country, $z->countries)) {
                $zoneName = $z->name;
                break;
            }
        }

        if ($zoneName == null) {
            return null;
        }

        foreach ($shipping as $s) {
            if ($s->zone == $zoneName) {
                return (float)$s->cost;
            }
        }

        return null;
    }

    protected static function createGenericBacker(object $faker, Record $record=null, int $backerNumber=null) : Record
    {
        if (is_null($record)) {
            $record = new Record();
        }

        $record
            ->setField(
                "Backer Number",
                $backerNumber != null
                ? $backerNumber
                : $faker->numberBetween(1, 9999)
            )
            ->setField("Backer UID", $faker->randomNumber(9))
            ->setField("Backer Name", $faker->name())
            ->setField("Email", $faker->safeEmail())
        ;

        return $record;
    }

    protected static function generateAddress(object $faker, array $countries, Record $record=null) : Record
    {
        if (is_null($record)) {
            $record = new Record();
        }

        $country = $countries[rand(0, count($countries) - 1)];

        $record
            ->setField("Shipping Name", $faker->name())
            ->setField("Shipping Address 1", $faker->address())
            ->setField("Shipping Address 2", $faker->secondaryAddress())
            ->setField("Shipping City", $faker->city())
            ->setField("Shipping State", $faker->stateAbbr())
            ->setField("Shipping Postal Code", $faker->postcode())
            ->setField("Shipping Country Name", $country->name)
            ->setField("Shipping Country Code", $country->code)
            ->setField("Shipping Phone Number", $faker->phoneNumber())
            ->setField("Shipping Delivery Notes", null)
        ;

        return $record;
    }

    protected static function generatePledge(
        object $faker,
        array $countries,
        Record $record=null,
        $currency="AUD",
        $currencySign="$",
        $shippingAmount=null,
        $rewardMinimum=null,
        $pledgeAmount=null,
        $billingCountry=null,
        $rewardTitle=null
    ) : Record {
        if (is_null($record)) {
            $record = new Record();
        }

        $shippingAmount = $shippingAmount == null
            ? rand(5, 55)
            : $shippingAmount
        ;
        $record->setField("Shipping Amount", sprintf(
            "%s%2.0f %s",
            $currencySign,
            $shippingAmount,
            $currency
        ));

        $rewardMinimum = $rewardMinimum == null
            ? rand(5, 200)
            : $rewardMinimum
        ;
        $record->setField("Reward Minimum", sprintf(
            "%s%2.0f %s",
            $currencySign,
            $rewardMinimum == null ? $faker->randomFloat(2, 1, 100) : $rewardMinimum,
            $currency
        ));

        $pledgeAmount = $pledgeAmount == null
            ? $rewardMinimum + $shippingAmount
            : $pledgeAmount
        ;
        $record->setField("Pledge Amount", sprintf(
            "%s%2.0f %s",
            $currencySign,
            $pledgeAmount,
            $currency
        ));

        $record
            ->setField("Pledged At", $faker->date('Y-m-d') . ", " . $faker->time('H:i'))
            ->setField("Rewards Sent?", 'No')
            ->setField("Reward ID", $faker->numberBetween(1000000, 9999999))
            ->setField("Pledged Status", 'collected')
            ->setField("Notes", $faker->realText(50))
            ->setField("Reward Title", $rewardTitle != null ? $rewardTitle : $faker->realText(20))
            ->setField("Billing State/Province", $faker->stateAbbr())
            ->setField(
                "Billing Country",
                $billingCountry == null
                ? $countries[rand(0, count($countries) - 1)]->code
                : $billingCountry
            )
            ->setField("Survey Response", $faker->date('Y-m-d') . ", " . $faker->time('H:i'))
        ;

        $record->setField("Shipping Country", $record->BillingCountry);

        return $record;
    }
}
