[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
![Packagist][packagist]

[packagist]: https://img.shields.io/packagist/v/flownative/noderepair-inconsistentidentifiers.svg

# Fix inconsistent node identifiers

This is a hotfix for Neos' node:repair command which adds an additional task for fixing inconsistent node
identifiers across workspaces.

In particular, the check detects nodes whose corresponding node in the live workspace (determined by the path
and dimensions) has a different node identifier.

The exact cause for this inconsistency is still unclear at the time of this writing.

## Usage

./flow node:repair --only fixNodesWithInconsistentIdentifier
