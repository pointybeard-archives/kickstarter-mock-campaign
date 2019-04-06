# Kickstarter Mock Campaign Data Generator

- Version: v0.1.0
- Date: April 6th 2019
- [Release notes](https://github.com/pointybeard/kickstarter-mock-campaign/blob/master/CHANGELOG.md)
- [GitHub repository](https://github.com/pointybeard/kickstarter-mock-campaign)

A utility for generating backer data exports, simulating a campaign.

## Installation

1. Clone this repository and run `composer update` first.
2. (Optional) Make `bin/generate` executable with `chmod +x bin/generate`.

## Usage

To see usage information, run the comment `bin/generate --help`. Included in this repository is an example settings JSON document, `settings.example.json`, which is used to seed the generator. It describes the campaign including funding, backers, rewards, surveys, and shipping data.

The quickest way to try this out is with the command:

    bin/generate -s settings.example.json -o exampleCampaignData.zip

## Support

If you believe you have found a bug, please report it using the [GitHub issue tracker](https://github.com/pointybeard/kickstarter-mock-campaign/issues),
or better yet, fork the library and submit a pull request.

## Contributing

We encourage you to contribute to this project. Please check out the [Contributing documentation](https://github.com/pointybeard/kickstarter-mock-campaign/blob/master/CONTRIBUTING.md) for guidelines about how to get involved.

## License

"Kickstarter Mock Campaign Data Generator" is released under the [MIT License](http://www.opensource.org/licenses/MIT).
