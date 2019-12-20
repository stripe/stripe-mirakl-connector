# How to release a new version?

1. Make sure the CHANGELOG and documentation are up to date with the latest changes.
2. Update the `VERSION` file and remove the `-SNAPSHOT` suffix.
3. Update the `nelmio_api_doc.yaml` with the new version.
4. Commit that new version, create a tag and a new Github release with the same version as the `VERSION` file.
5. A CircleCI job should run on the tag and publish a new Docker image.
6. Bump the version in the VERSION file and add a `-SNAPSHOT` suffix to prepare the next development cycle.
