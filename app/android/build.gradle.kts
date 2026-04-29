allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

// Some legacy Flutter plugins (e.g. older package_info_plus) read
// rootProject.ext.compileSdkVersion in their own Groovy build.gradle.
// With Kotlin DSL we have to surface those values explicitly, otherwise
// configuration of the plugin sub-project fails with
// "compileSdkVersion is not specified" / NPE on substring().
extra["compileSdkVersion"] = 34
extra["minSdkVersion"] = 23
extra["targetSdkVersion"] = 34
extra["kotlinVersion"] = "1.9.22"

val newBuildDir: Directory = rootProject.layout.buildDirectory.dir("../../build").get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects { project.evaluationDependsOn(":app") }

tasks.register<Delete>("clean") { delete(rootProject.layout.buildDirectory) }
