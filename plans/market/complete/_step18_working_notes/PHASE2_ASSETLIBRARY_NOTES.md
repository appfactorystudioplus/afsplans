# Phase 2: AssetLibrary Dual-Root Modification

## Current State (Single-Root)
- AssetLibrary::ScanProject() scans only `Assets/` directory
- All paths in m_Records, m_PathToId are relative to Assets/
- GetAssetFileSystemPath(relPath) resolves as: `Project::GetAssetDirectory() / relPath`

## Required Changes for Dual-Root

### Approach: Path Prefixing
To avoid path collisions while keeping the existing index structure:

1. When scanning `Assets/`:
   - Store paths as-is (e.g., "Textures/icon.png")
   - These work with current GetAssetFileSystemPath()

2. When scanning `Packages/`:
   - Prefix paths with "Packages/" (e.g., "Packages/com.pkg@1.0/Assets/icon.png")
   - These require special handling in GetAssetFileSystemPath()

### Implementation Steps

#### 1. Extract Scanning Logic
```cpp
private:
    void ScanDirectory(
        const std::filesystem::path& rootDir,
        const std::string& pathPrefix);  // "" for Assets/, "Packages" for Packages/
```

#### 2. Modify ScanProject()
```cpp
void AssetLibrary::ScanProject()
{
    m_Records.clear();
    m_PathToId.clear();
    m_OriginToId.clear();

    if (!Project::GetActive())
        return;

    // Scan Assets directory
    ScanDirectory(Project::GetAssetDirectory(), "");

    // Scan Packages directory
    std::filesystem::path packagesDir = Project::GetPackagesDirectory();
    std::error_code existsEc;
    if (std::filesystem::exists(packagesDir, existsEc))
        ScanDirectory(packagesDir, "Packages");
}
```

#### 3. Update GetAssetFileSystemPath()
```cpp
std::filesystem::path AssetLibrary::GetAssetFileSystemPath(
    const std::filesystem::path& path)
{
    // Check if path starts with "Packages/"
    std::string pathStr = path.generic_string();
    if (pathStr.find("Packages/") == 0 || pathStr.find("Packages\\") == 0)
    {
        // Remove the "Packages" prefix for relative calculation
        std::string relativePath = pathStr.substr(9);  // strlen("Packages/") == 9
        return Project::GetPackagesDirectory() / relativePath;
    }
    
    // Normal Assets/ path
    return Project::GetAssetDirectory() / path;
}
```

#### 4. Mark Package Assets as Read-Only
In ContentBrowserPanel, when displaying assets:
- Assets with paths starting with "Packages/" should be marked read-only
- Prevent drag/rename/delete operations on package content
- Show visual indicator (e.g., lock icon)

## Files to Modify
1. `Joystick/src/Joystick/Asset/AssetLibrary.h` - Add ScanDirectory() private method
2. `Joystick/src/Joystick/Asset/AssetLibrary.cpp` - Implement the changes above
3. `JoystickWindow/src/Panels/ContentBrowserPanel.cpp` - Add read-only handling for package assets

## Testing This Locally (Before Networking)
1. Manually create `<project>/Packages/test-pkg@1.0/Assets/icon.png`
2. Open the project in the editor
3. Run AssetLibrary::ScanProject()
4. Verify:
   - Asset appears in registry with path "Packages/test-pkg@1.0/Assets/icon.png"
   - Content Browser shows it as read-only
   - No collision with similarly-named assets in Assets/

## No Changes Needed
- AssetRecord struct (paths just contain the prefix)
- GetPath(), GetId() methods (they work with prefixed paths)
- GetType(), IsMissing(), etc. (they work with any AssetID)
- Typed loading methods (LoadTexture, etc) - they just call the resolved filesystem path
