<?php

declare(strict_types=1);

namespace BzKarma;

class Scoring
{
    /*
        This array contains our uplift value business logic.
    */
    public array $karma = [
        'type' => [
            'defect'      => 2,
            'enhancement' => 1,
            'task'        => 0,
        ],
        'priority' => [
            'P1' => 5,
            'P2' => 4,
            'P3' => 3,
            'P4' => 2,
            'P5' => 1,
            '--' => 0,
        ],
        'severity' => [
            'S1'  => 10,
            'S2'  => 5,
            'S3'  => 2,
            'S4'  => 1,
            'N/A' => 0,
            '--'  => 0,
        ],
        'keywords' => [
            'topcrash-startup'    => 10,
            'topcrash'            => 5,
            'dataloss'            => 3,
            'crash'               => 2,
            'hang'                => 2,
            'regression'          => 2,
            'access'              => 2,
            'site-compat'         => 2,
            'parity-chrome'       => 1,
            'parity-edge'         => 1,
            'parity-safari'       => 1,
            'perf'                => 1,
            'perf:animation'      => 1,
            'perf:frontend'       => 1,
            'perf:pageload'       => 1,
            'perf:resource-use'   => 1,
            'perf:responsiveness' => 1,
            'perf:startup'        => 1,
            'compat'              => 1,
            'papercut'            => 1,
            'polish'              => 1,
            'power'               => 1,
        ],
        'duplicates'  =>  2, // Points for each duplicate
        'regressions' => -2, // Negative Points for regressions
        'tracking_firefox_nightly' => [
            'blocking' => 100,
            '+'        => 4,
            '?'        => 2,
            '-'        => 0,
            '---'      => 0,
        ],
        'tracking_firefox_beta' => [
            'blocking' => 100,
            '+'        => 4,
            '?'        => 2,
            '-'        => 0,
            '---'      => 0,
        ],
        'tracking_firefox_release' => [
            'blocking' => 100,
            '+'        => 4,
            '?'        => 2,
            '-'        => 0,
            '---'      => 0,
        ],
        'webcompat' => [
            'P1'  => 5,
            'P2'  => 4,
            'P3'  => 3,
            '?'   => 1,
            '---' => 0,
        ],
        'perf_impact' => [
            'high'   => 2,
            'medium' => 1,
            'low'    => 0,
            'none'   => 0,
            '?'      => 0,
            '---'    => 0,
        ],
        'cc' => 0.1, // Decimal point for each cc, we round the total value
    ];

    /*
        This array stores the bug data provided by the Bugzilla rest API
        The list of fields retrieved are:

        id, type, summary, priority, severity, keywords, duplicates, regressions, cf_webcompat_priority,
        cf_tracking_firefox_nightly, cf_tracking_firefox_beta, cf_tracking_firefox_release, cc,
        cf_performance_impact

        The fields actually retrieved for tracking requests have release numbers, ex:
        cf_tracking_firefox112, cf_tracking_firefox111, cf_tracking_firefox110,
        cf_status_firefox112, cf_status_firefox111, cf_status_firefox110

        See Bug 1819638 - JSON API should support release aliases - https://bugzil.la/1819638
    */
    public array $bugsDetails;

    /*
        We need Firefox release numbers internally, the release train is provided in the constructor.
    */
    private string $release;
    private string $beta;
    private string $nightly;

    /*
        The library returns a value of 0 for bugs already uplifted,
        We may want to bypass this setting to look at bugs in past trains
     */
    private bool $nightlyScoreOnly = false;

    /*
        We work from a dataset provided by the Bugzilla rest API
    */
    public function __construct(array $bugsDetails, int $release)
    {
        $this->bugsDetails = $bugsDetails;
        $this->release = strval($release);
        $this->beta    = strval($this->release + 1);
        $this->nightly = strval($this->release + 2);
    }

    /*
        Pass true to this method before calling getScore()
        to get the value of an already uplifted bug
     */
    public function scoreNightlyOnly(bool $status): void
    {
        $this->nightlyScoreOnly = $status;
    }

    public function getAllBugsScores(): array
    {
        $bugs = [];

        foreach ($this->bugsDetails as $bugNumber => $details) {
           $bugs[$bugNumber] = $this->getBugScore($bugNumber);
        }

        arsort($bugs); // We sort in reverse order to list best bugs first

        return $bugs;
    }

    /*
        This is the method that contains the business logic.
    */
    public function getBugScoreDetails(int $bugNumber): array
    {
        /*
            If we don't have the bug in store (private bugs), return 0.
            This part of the logic is only needed when using the external public API.
        */
        if (! isset($this->bugsDetails[$bugNumber])) {
            return $this->zeroBugScore();
        }

        if (array_key_exists('cf_status_firefox' . $this->beta, $this->bugsDetails[$bugNumber])
            && array_key_exists('cf_status_firefox' . $this->release, $this->bugsDetails[$bugNumber])) {
            /*
                Beta and release are  not affected, not a candidate for uplifting
             */
            if (in_array($this->bugsDetails[$bugNumber]['cf_status_firefox' . $this->beta], ['unaffected', 'disabled'])
                && in_array($this->bugsDetails[$bugNumber]['cf_status_firefox' . $this->release], ['unaffected', 'disabled'])) {
                return $this->zeroBugScore();
            }

            if ($this->nightlyScoreOnly === false) {
                /*
                    Bug already uplifted, uplift value is 0
                 */
                if (in_array($this->bugsDetails[$bugNumber]['cf_status_firefox' . $this->beta], ['fixed', 'verified'])
                    && in_array($this->bugsDetails[$bugNumber]['cf_status_firefox' . $this->release], ['fixed', 'verified', 'unaffected'])) {
                    return $this->zeroBugScore();
                }
            }
        }

        /*
            We loop through all the bug keywords and check if they have an internal value.
            Then we add the points they have to the total for keywords.
        */
        $keywords_value = 0;

        foreach ($this->bugsDetails[$bugNumber]['keywords'] as $keyword) {
            if (array_key_exists($keyword, $this->karma['keywords'])) {
                $keywords_value += $this->karma['keywords'][$keyword];
            }
        }


        return [
            /*
                Severity and Priority fields had other values in the past like normal, trivial…
                We ignore these values for now.
            */
            'priority'    => $this->karma['priority'][$this->bugsDetails[$bugNumber]['priority']] ?? 0,
            'severity'    => $this->karma['severity'][$this->bugsDetails[$bugNumber]['severity']] ?? 0,
            'type'        => $this->karma['type'][$this->bugsDetails[$bugNumber]['type']],
            'keywords'    => $keywords_value,
            'duplicates'  => count($this->bugsDetails[$bugNumber]['duplicates'])  * $this->karma['duplicates'],
            'regressions' => count($this->bugsDetails[$bugNumber]['regressions']) * $this->karma['regressions'],
            'webcompat'   => $this->getFieldValue($bugNumber, 'cf_webcompat_priority', 'webcompat'),
            'perf_impact' => $this->getFieldValue($bugNumber, 'cf_performance_impact', 'perf_impact'),
            'cc'          => (int) floor(count($this->bugsDetails[$bugNumber]['cc']) * $this->karma['cc']),
            'tracking_firefox' . $this->nightly =>
                $this->getFieldValue($bugNumber, 'cf_tracking_firefox' . $this->nightly, 'tracking_firefox_nightly'),
            'tracking_firefox' . $this->beta =>
                $this->getFieldValue($bugNumber, 'cf_tracking_firefox' . $this->beta, 'tracking_firefox_beta'),
            'tracking_firefox' . $this->release =>
                $this->getFieldValue($bugNumber, 'cf_tracking_firefox' . $this->release, 'tracking_firefox_release'),
        ];
    }

    public function getBugScore(int $bugNumber): int {
        return array_sum($this->getBugScoreDetails($bugNumber));
    }

    /**
     * Some fields are not available for all components so we need to check
     * for their availability and we set it to a 0 karma if it doesn't exist.
     */
    private function getFieldValue(int $bugNumber, string $bz_field, string $local_field): int {
        return isset($this->bugsDetails[$bugNumber][$bz_field])
            ? $this->karma[$local_field][$this->bugsDetails[$bugNumber][$bz_field]]
            : 0;
    }

    private function zeroBugScore(): array {
        return  [
                'type'        => 0,
                'priority'    => 0,
                'severity'    => 0,
                'keywords'    => 0,
                'duplicates'  => 0,
                'regressions' => 0,
                'webcompat'   => 0,
                'perf_impact' => 0,
                'cc'          => 0,
                'tracking_firefox' . $this->nightly => 0,
                'tracking_firefox' . $this->beta    => 0,
                'tracking_firefox' . $this->release => 0,
        ];
    }
}