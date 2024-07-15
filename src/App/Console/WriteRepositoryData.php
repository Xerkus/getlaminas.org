<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function assert;
use function base64_decode;
use function count;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function date;
use function file_put_contents;
use function getcwd;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function urlencode;

use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_URL;
use const FILE_USE_INCLUDE_PATH;
use const JSON_PRETTY_PRINT;

class WriteRepositoryData extends Command
{
    private const ARGUMENT_USER_AGENT = 'userAgent';
    private const ARGUMENT_TOKEN      = 'token';

    private string $propsFile;
    private string $cachePath;

    private array $orgs = [
        'laminas',
        'laminas-api-tools',
        'mezzio',
    ];

    private array $exceptions = [];

    public function __construct()
    {
        parent::__construct();

        $this->propsFile = 'properties.json';
        $this->cachePath = getcwd() . "/public/share";
    }

    protected function configure(): void
    {
        $this->setName('app:generate-repository-data');
        $this->setDescription('Write repository data to a json file.');
        $this->setHelp(
            'Writes all custom properties from all repositories of the configured organizations to a json file.'
        );
        $this->addArgument(self::ARGUMENT_USER_AGENT, InputArgument::OPTIONAL, 'GitHub user agent.');
        $this->addArgument(self::ARGUMENT_TOKEN, InputArgument::OPTIONAL, 'GitHub token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userAgent = $input->getArgument(self::ARGUMENT_USER_AGENT);
        if (! $userAgent) {
            $userAgent = $_ENV['PLATFORM_APPLICATION_NAME'];
        }
        assert(is_string($userAgent));

        $token = $input->getArgument(self::ARGUMENT_TOKEN);
        if (! $token) {
            $variables = json_decode(base64_decode($_ENV['PLATFORM_VARIABLES']), true);
            assert(is_array($variables));
            assert(isset($variables['REPO_TOKEN']));

            $token = $variables['REPO_TOKEN'];
        }
        assert(is_string($token));

        $this->generateDataFile($userAgent, $token);

        return 0;
    }

    private function generateDataFile(string $userAgent, string $token): void
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: ' . $userAgent,
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $singleResult = ['last_updated' => date('Y-m-d H:i:s')];

        /** @var string $org */
        foreach ($this->orgs as $org) {
            $perPage    = 100;
            $page       = 1;
            $visibility = urlencode('is:public');

            do {
                $query = "q=$visibility&per_page=$perPage&page=$page";
                $url   = "https://api.github.com/orgs/" . $org . "/properties/values?$query";

                curl_setopt($curl, CURLOPT_URL, $url);

                $curlResult = curl_exec($curl);
                assert(is_string($curlResult));

                $decodedRes = json_decode($curlResult, true);
                assert(is_array($decodedRes));

                /** @var array $value */
                foreach ($decodedRes as $value) {
                    if (in_array($value['repository_name'], $this->exceptions)) {
                        continue;
                    }

                    /** @var array $propertyData */
                    foreach ($value['properties'] as $propertyData) {
                        if ($propertyData['property_name'] === 'is-published-component') {
                            if ($propertyData['value'] === 'false') {
                                continue 2;
                            }
                        }
                    }

                    $singleResult[$org][] = [
                        'name'       => $value['repository_name'],
                        'properties' => $value['properties'],
                    ];
                }
                $page++;
            } while (count($decodedRes) === $perPage);
        }

        file_put_contents(
            sprintf('%s/%s', $this->cachePath, $this->propsFile),
            json_encode($singleResult, JSON_PRETTY_PRINT),
            FILE_USE_INCLUDE_PATH
        );

        curl_close($curl);
    }
}
