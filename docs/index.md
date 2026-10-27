# civikitchen

CiviCRM Docker images with the development toolchain built in, an extension
template, and reusable GitHub workflows that test, check and release CiviCRM
extensions. The host needs Docker with the compose plugin and bash. Everything
else runs in the image; browser suites and `ck demo` also need PHP 8.1 or newer
and the buildx plugin.

| You want to … | Read |
|---------------|------|
| Develop an extension | [Develop an extension](extension-development.md): dev stack, tests, civix, analysis, debugging |
| Know what the checks enforce | [Standards and checks](extension-standards.md) |
| Test and release it on GitHub | [Releasing an extension](extension-releases.md), [Reusable workflows](reusable-workflows.md) |
| Work on CiviCRM core | [Core development](core-development.md): core test suites, unreleased branches |
| Try or demo CiviCRM | [Images and demos](images.md#demo-images): demo images and profiles |
| Look up an image setting | [Images](images.md), [Configuration](configuration.md) |

Start a new extension:

```bash
git clone https://github.com/civico-de/civikitchen
civikitchen/scaffold/ckcreate myext \
    --author "Example Maintainer" --email dev@example.org --copyright "Example Org"
cd myext
git add -A && git commit -m "Scaffold myext"   # after reviewing it
../civikitchen/scaffold/ckup --wait --wait-timeout 900   # dev stack on free ports; waits out the first boot
../civikitchen/scaffold/ckx ck ci                         # tests and every check, as CI runs them
```

Or look around in a throwaway CiviCRM with demo data:

```bash
docker run -d -p 80:80 --name civicrm ghcr.io/civico-de/civikitchen:standalone-demo
```

Open <http://localhost> and log in as admin / admin.

Extensions pin `@v1`. That one version covers the workflows, the template,
the ck tools and the v1 images, as [Releases](releases.md) explains.
